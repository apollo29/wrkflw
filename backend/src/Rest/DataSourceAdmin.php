<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Contracts\AdminTriggerRepositoryInterface;
use WorkflowEngine\Contracts\AuditLogInterface;
use WorkflowEngine\Contracts\ModelUsageQueryInterface;
use WorkflowEngine\Contracts\RestConnectionRepositoryInterface;
use WorkflowEngine\Contracts\RestModelRepositoryInterface;
use WorkflowEngine\Contracts\SecretStoreInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Engine\SystemClock;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\ModelUsage;
use WorkflowEngine\Security\BlockedUrlException;
use WorkflowEngine\Security\SafeHttpClient;
use WorkflowEngine\Security\UrlGuard;

/**
 * Datenquellen ohne Code einrichten (F-133 bis F-145, Reiter 9.16). Der einzige Einstieg
 * für die API; Anmeldung und Rechte prüft vorher die Host-App (F-104).
 *
 * Jede Änderung geht mit Actor und Zeitpunkt ins Protokoll, ohne Zugangsdaten (F-145).
 * Ungültige Eingaben werfen {@see DataSourceValidationException} mit Fehlern je Feld.
 */
final readonly class DataSourceAdmin
{
    private const string ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    private const string NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/';

    private RestClient $client;

    public function __construct(
        private RestConnectionRepositoryInterface $connections,
        private RestModelRepositoryInterface $models,
        private AdminTriggerRepositoryInterface $triggers,
        private AuditLogInterface $audit,
        private SecretStoreInterface $secrets,
        private UrlGuard $guard,
        SafeHttpClient $http,
        private DataModelRegistry $registry,
        private DataGateway $gateway,
        private WorkflowRepositoryInterface $definitions,
        private ModelUsageQueryInterface $usage,
        private RestOptions $options = new RestOptions(),
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->client = new RestClient($http, $secrets);
    }

    // ------------------------------------------------------------ Verbindungen

    /**
     * Legt eine Verbindung an oder ändert sie. Die ID ist nach dem Anlegen fest; ohne neues
     * Geheimnis bleibt das hinterlegte.
     */
    public function saveConnection(Actor $actor, RestConnection $input, #[\SensitiveParameter] ?string $secret): RestConnection
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        if (preg_match(self::ID_PATTERN, $input->id) !== 1) {
            $errors['id'][] = 'Die ID besteht aus Kleinbuchstaben, Ziffern, - und _ (höchstens 64 Zeichen).';
        }
        if (trim($input->name) === '') {
            $errors['name'][] = 'Bitte einen Namen angeben.';
        }
        try {
            $this->guard->check($input->baseUrl);
        } catch (BlockedUrlException $e) {
            $errors['baseUrl'][] = $e->getMessage();
        }
        if ($input->authType === AuthType::ApiKey && ($input->authHeader === null || trim($input->authHeader) === '')) {
            $errors['authHeader'][] = 'Bitte die Kopfzeile für den API-Schlüssel angeben.';
        }
        self::throwIf($errors);

        $existing = $this->connections->findConnection($input->id);
        $encrypted = $existing?->encryptedSecret;
        if ($secret !== null && $secret !== '') {
            $encrypted = $this->secrets->encrypt($secret);
        }
        if (!$input->authType->needsSecret()) {
            $encrypted = null;
        }

        $connection = new RestConnection($input->id, trim($input->name), rtrim(trim($input->baseUrl), '/'), $input->authType, $input->authType === AuthType::ApiKey ? trim((string) $input->authHeader) : null, $encrypted);
        $this->connections->saveConnection($connection);
        $this->log($actor, $existing === null ? 'connection.created' : 'connection.updated', 'connection', $connection->id, [
            'changed' => $existing === null ? ['name', 'baseUrl', 'authType'] : self::changed($existing->toPublicArray(), $connection->toPublicArray()),
            'secretReplaced' => $secret !== null && $secret !== '',
        ]);

        return $connection;
    }

    /** Ersetzt die Zugangsdaten. Anzeigen lassen sie sich nie (F-135). */
    public function replaceSecret(Actor $actor, string $id, #[\SensitiveParameter] string $secret): void
    {
        $connection = $this->connections->findConnection($id) ?? throw new NotFoundException("Verbindung '{$id}' nicht gefunden.");
        if ($secret === '') {
            self::throwIf(['secret' => ['Bitte die neuen Zugangsdaten angeben.']]);
        }
        $this->connections->saveConnection($connection->withEncryptedSecret($this->secrets->encrypt($secret)));
        $this->log($actor, 'connection.secret_replaced', 'connection', $id);
    }

    public function deleteConnection(Actor $actor, string $id): void
    {
        $users = $this->connectionUsage($id);
        if ($users !== []) {
            self::throwIf(['connection' => ['Die Verbindung wird verwendet von: ' . implode(', ', $users) . '.']]);
        }
        $this->connections->deleteConnection($id);
        $this->log($actor, 'connection.deleted', 'connection', $id);
    }

    /** «Verbindung testen»: Statuscode und Dauer, keine Inhalte (F-139). */
    public function testConnection(string $id): ConnectionTestResult
    {
        $connection = $this->connections->findConnection($id) ?? throw new NotFoundException("Verbindung '{$id}' nicht gefunden.");
        $start = hrtime(true);
        try {
            $result = $this->client->ping($connection);

            return new ConnectionTestResult($result->status < 400, $result->status, $result->durationMs);
        } catch (WorkflowException $e) {
            return new ConnectionTestResult(false, null, (int) ((hrtime(true) - $start) / 1_000_000), $e->getMessage());
        }
    }

    /**
     * @return list<string> Namen der REST-Datenmodelle, die die Verbindung nutzen
     */
    public function connectionUsage(string $id): array
    {
        $names = [];
        foreach ($this->models->allModels() as $model) {
            if ($model->connectionId === $id) {
                $names[] = $model->name;
            }
        }

        return $names;
    }

    // ------------------------------------------------------------ Datenmodelle

    public function saveModel(Actor $actor, RestModelSpec $spec): RestModelSpec
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        $existing = $this->models->findModel($spec->name);
        if (preg_match(self::NAME_PATTERN, $spec->name) !== 1) {
            $errors['name'][] = 'Der Name besteht aus Buchstaben, Ziffern, _ und - und beginnt mit einem Buchstaben.';
        } elseif ($existing === null && $this->registry->has($spec->name)) {
            $errors['name'][] = "Ein Datenmodell '{$spec->name}' gibt es bereits in der Host-App; REST-Datenmodelle ergänzen, ersetzen aber keines.";
        }
        if ($this->connections->findConnection($spec->connectionId) === null) {
            $errors['connection'][] = "Die Verbindung '{$spec->connectionId}' gibt es nicht.";
        }

        $canWrite = isset($spec->operations['update']) || isset($spec->operations['create']);
        $names = [];
        foreach ($spec->fields as $field) {
            if (isset($names[$field->name])) {
                $errors['fields.' . $field->name][] = 'Der Feldname kommt doppelt vor.';
            }
            $names[$field->name] = true;
            if ($field->writable && !$canWrite) {
                $errors['fields.' . $field->name][] = '«schreibbar» braucht den Vorgang Ändern oder Anlegen.';
            } elseif ($field->writable && !$this->options->allowWrites) {
                $errors['fields.' . $field->name][] = 'Schreiben über REST ist in dieser Anwendung gesperrt.';
            }
        }
        if (!isset($names[$spec->idField])) {
            $errors['idField'][] = "Das ID-Feld '{$spec->idField}' ist nicht beschrieben.";
        }

        foreach ($this->usage->modelUsage($spec->name) as $use) {
            foreach ($use->fields as $field) {
                if (!isset($names[$field])) {
                    $errors['fields.' . $field][] = "Das Feld wird noch verwendet in {$use->definitionId} v{$use->version} ({$use->step}).";
                } elseif ($spec->field($field)?->writable !== true && $this->writesField($use)) {
                    $errors['fields.' . $field][] = "Das Feld wird in {$use->definitionId} v{$use->version} ({$use->step}) geschrieben und muss schreibbar bleiben.";
                }
            }
        }

        if ($errors === []) {
            try {
                $spec->toDataModel($this->options->allowWrites);
            } catch (DataModelException $e) {
                $errors['model'] = [$e->getMessage()];
            }
        }
        self::throwIf(array_map(static fn (array $m): array => array_values(array_unique($m)), $errors));

        $this->models->saveModel($spec);
        $this->log($actor, $existing === null ? 'model.created' : 'model.updated', 'model', $spec->name, [
            'fields' => array_map(static fn (RestFieldSpec $f): string => $f->name, $spec->fields),
            'operations' => array_keys($spec->operations),
        ]);

        return $spec;
    }

    public function deleteModel(Actor $actor, string $name): void
    {
        $usage = $this->modelUsage($name);
        $reasons = array_map(static fn (ModelUsage $u): string => "{$u->definitionId} v{$u->version} ({$u->step})", $usage['definitions']);
        foreach ($usage['triggers'] as $trigger) {
            $reasons[] = "Trigger {$trigger}";
        }
        if ($reasons !== []) {
            self::throwIf(['model' => ['Das Datenmodell wird noch verwendet in: ' . implode(', ', $reasons) . '.']]);
        }
        $this->models->deleteModel($name);
        $this->log($actor, 'model.deleted', 'model', $name);
    }

    /**
     * @return array{definitions: list<ModelUsage>, triggers: list<string>}
     */
    public function modelUsage(string $name): array
    {
        $triggers = [];
        foreach ($this->triggers->allTriggers() as $trigger) {
            if ($trigger->model === $name) {
                $triggers[] = $trigger->id;
            }
        }

        return ['definitions' => $this->usage->modelUsage($name), 'triggers' => $triggers];
    }

    /** «Beispiel abrufen»: Antwort als Baum mit Pfaden, nicht gespeichert (F-139). */
    public function sample(RestModelSpec $spec, string $id): SampleResult
    {
        $connection = $this->connections->findConnection($spec->connectionId)
            ?? throw new NotFoundException("Verbindung '{$spec->connectionId}' nicht gefunden.");
        $result = $this->client->send($connection, $spec, 'load', $id);

        return SampleResult::fromTree($result->status, json_decode($result->body, true));
    }

    // ------------------------------------------------------------ Trigger

    public function saveTrigger(Actor $actor, AdminTrigger $trigger): AdminTrigger
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        if (preg_match(self::ID_PATTERN, $trigger->id) !== 1) {
            $errors['id'][] = 'Die ID besteht aus Kleinbuchstaben, Ziffern, - und _.';
        }
        $model = null;
        try {
            $model = $this->registry->get($trigger->model);
            $spec = $this->models->findModel($trigger->model);
            if ($spec !== null && $spec->operation('find') === null) {
                $errors['model'][] = 'Das Datenmodell erlaubt den Vorgang «Suchen» nicht.';
            }
        } catch (DataModelException $e) {
            $errors['model'][] = $e->getMessage();
        }

        $inputs = [];
        try {
            $inputs = $this->definitions->findDefinition($trigger->definitionId)->inputs;
        } catch (NotFoundException $e) {
            $errors['definitionId'][] = $e->getMessage();
        }

        if ($model !== null) {
            foreach ($trigger->conditions as $condition) {
                if (!$model->hasField($condition->field)) {
                    $errors['conditions'][] = "Das Feld '{$condition->field}' gibt es im Datenmodell nicht.";
                }
            }
            foreach ($trigger->inputs as $input => $field) {
                if (!$model->hasField($field)) {
                    $errors['inputs.' . $input][] = "Das Feld '{$field}' gibt es im Datenmodell nicht.";
                }
            }
        }
        foreach ($inputs as $input) {
            if ($input->required && !isset($trigger->inputs[$input->name])) {
                $errors['inputs.' . $input->name][] = "Die Pflichtangabe «{$input->label}» braucht ein Feld.";
            }
        }
        if ($trigger->intervalMinutes < AdminTrigger::MIN_INTERVAL_MINUTES) {
            $errors['intervalMinutes'][] = 'Geprüft wird höchstens alle ' . AdminTrigger::MIN_INTERVAL_MINUTES . ' Minuten.';
        }
        if ($trigger->lockDays < 1) {
            $errors['lockDays'][] = 'Die Sperrfrist beträgt mindestens einen Tag.';
        }
        self::throwIf($errors);

        $existing = $this->triggers->findTrigger($trigger->id);
        $saved = new AdminTrigger(
            $trigger->id,
            trim($trigger->name) !== '' ? trim($trigger->name) : $trigger->id,
            $trigger->model,
            $trigger->conditions,
            $trigger->definitionId,
            $trigger->inputs,
            $trigger->intervalMinutes,
            $trigger->lockDays,
            $trigger->active,
            $existing?->lastPolledAt,
        );
        $this->triggers->saveTrigger($saved);
        $this->log($actor, $existing === null ? 'trigger.created' : 'trigger.updated', 'trigger', $saved->id, [
            'model' => $saved->model,
            'definitionId' => $saved->definitionId,
            'active' => $saved->active,
        ]);

        return $saved;
    }

    public function deleteTrigger(Actor $actor, string $id): void
    {
        $this->triggers->deleteTrigger($id);
        $this->log($actor, 'trigger.deleted', 'trigger', $id);
    }

    /** «Jetzt prüfen»: wie viele Datensätze treffen zu; startet nichts (9.16). */
    public function checkTrigger(AdminTrigger $trigger): int
    {
        return count($this->gateway->find($trigger->model, $trigger->conditions, $this->options->maxResults));
    }

    // ------------------------------------------------------------ Hilfen

    private function writesField(ModelUsage $use): bool
    {
        try {
            $step = $this->definitions->findDefinition($use->definitionId, $use->version)->step($use->step);
        } catch (NotFoundException) {
            return false;
        }

        return $step->action === 'write_data';
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function log(Actor $actor, string $action, string $type, string $id, array $detail = []): void
    {
        $this->audit->record(new AuditEntry($this->clock->now(), $actor, $action, $type, $id, $detail));
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<string>
     */
    private static function changed(array $before, array $after): array
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function throwIf(array $errors): void
    {
        if ($errors !== []) {
            throw new DataSourceValidationException($errors);
        }
    }
}
