<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Model\ModelUsage;
use WorkflowEngine\Rest\AdminTrigger;
use WorkflowEngine\Rest\AuditEntry;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Rest\RestConnection;
use WorkflowEngine\Rest\RestModelSpec;

/**
 * Reiter «Datenquellen» (9.16): Verbindungen, REST-Datenmodelle, Trigger, Protokoll.
 * Nur mit Admin-Recht; jede Änderung läuft über {@see \WorkflowEngine\Rest\DataSourceAdmin}
 * mit der handelnden Person. Zugangsdaten gehen hinein, aber nie hinaus (F-135).
 */
final readonly class DataSourceController
{
    public function __construct(private DataSources $d)
    {
    }

    // ------------------------------------------------------------ Verbindungen

    public function listConnections(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonIo::json($response, [
            'connections' => array_map(static fn (RestConnection $c): array => $c->toPublicArray(), $this->d->connections->allConnections()),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function getConnection(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonIo::json($response, $this->connection(JsonIo::arg($args, 'id'))->toPublicArray());
    }

    public function createConnection(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = JsonIo::body($request);
        $id = trim(JsonIo::string($body, 'id') ?? '');
        if ($this->d->connections->findConnection($id) !== null) {
            throw new ConflictException("Eine Verbindung '{$id}' gibt es bereits.", 'exists');
        }
        $saved = $this->d->admin->saveConnection(JsonIo::access($request)->actor, self::connectionInput($id, $body), self::secret($body));

        return JsonIo::json($response, $saved->toPublicArray(), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateConnection(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->connection(JsonIo::arg($args, 'id'))->id;
        $body = JsonIo::body($request);
        $saved = $this->d->admin->saveConnection(JsonIo::access($request)->actor, self::connectionInput($id, $body), self::secret($body));

        return JsonIo::json($response, $saved->toPublicArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function replaceSecret(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'id');
        $this->d->admin->replaceSecret(JsonIo::access($request)->actor, $id, self::secret(JsonIo::body($request)) ?? '');

        return JsonIo::json($response, $this->connection($id)->toPublicArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteConnection(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->connection(JsonIo::arg($args, 'id'))->id;
        $this->d->admin->deleteConnection(JsonIo::access($request)->actor, $id);

        return JsonIo::empty($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function testConnection(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $result = $this->d->admin->testConnection(JsonIo::arg($args, 'id'));

        return JsonIo::json($response, [
            'ok' => $result->ok,
            'status' => $result->status,
            'durationMs' => $result->durationMs,
            'message' => $result->message,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function connectionUsage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->connection(JsonIo::arg($args, 'id'))->id;

        return JsonIo::json($response, ['id' => $id, 'models' => $this->d->admin->connectionUsage($id)]);
    }

    // ------------------------------------------------------------ Datenmodelle

    public function listModels(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonIo::json($response, [
            'models' => array_map(static fn (RestModelSpec $m): array => $m->toArray(), $this->d->models->allModels()),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function getModel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonIo::json($response, $this->model(JsonIo::arg($args, 'name'))->toArray());
    }

    public function createModel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $spec = RestModelSpec::fromArray(JsonIo::body($request));
        if ($this->d->models->findModel($spec->name) !== null) {
            throw new ConflictException("Ein Datenmodell '{$spec->name}' gibt es bereits.", 'exists');
        }

        return JsonIo::json($response, $this->d->admin->saveModel(JsonIo::access($request)->actor, $spec)->toArray(), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateModel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $name = $this->model(JsonIo::arg($args, 'name'))->name;
        // Der Name ist nach dem Anlegen fest; er kommt aus dem Pfad.
        $spec = RestModelSpec::fromArray(['name' => $name] + JsonIo::body($request));

        return JsonIo::json($response, $this->d->admin->saveModel(JsonIo::access($request)->actor, $spec)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteModel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $name = $this->model(JsonIo::arg($args, 'name'))->name;
        $this->d->admin->deleteModel(JsonIo::access($request)->actor, $name);

        return JsonIo::empty($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function modelUsage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $name = $this->model(JsonIo::arg($args, 'name'))->name;
        $usage = $this->d->admin->modelUsage($name);

        return JsonIo::json($response, [
            'name' => $name,
            'definitions' => array_map(
                static fn (ModelUsage $u): array => ['definitionId' => $u->definitionId, 'version' => $u->version, 'step' => $u->step, 'fields' => $u->fields],
                $usage['definitions'],
            ),
            'triggers' => $usage['triggers'],
        ]);
    }

    /** Beispiel abrufen mit einer noch nicht gespeicherten Beschreibung: `{model, id}`. */
    public function sample(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = JsonIo::body($request);
        $spec = RestModelSpec::fromArray(JsonIo::assoc($body['model'] ?? null));
        $result = $this->d->admin->sample($spec, JsonIo::required($body, 'id'));

        return JsonIo::json($response, ['status' => $result->status, 'tree' => $result->tree, 'paths' => $result->paths]);
    }

    // ------------------------------------------------------------ Trigger

    public function listTriggers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonIo::json($response, [
            'triggers' => array_map(static fn (AdminTrigger $t): array => $t->toArray(), $this->d->triggers->allTriggers()),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function getTrigger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonIo::json($response, $this->trigger(JsonIo::arg($args, 'id'))->toArray());
    }

    public function createTrigger(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $trigger = AdminTrigger::fromArray(JsonIo::body($request));
        if ($this->d->triggers->findTrigger($trigger->id) !== null) {
            throw new ConflictException("Einen Trigger '{$trigger->id}' gibt es bereits.", 'exists');
        }

        return JsonIo::json($response, $this->d->admin->saveTrigger(JsonIo::access($request)->actor, $trigger)->toArray(), 201);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateTrigger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->trigger(JsonIo::arg($args, 'id'))->id;
        $trigger = AdminTrigger::fromArray(['id' => $id] + JsonIo::body($request));

        return JsonIo::json($response, $this->d->admin->saveTrigger(JsonIo::access($request)->actor, $trigger)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteTrigger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $this->trigger(JsonIo::arg($args, 'id'))->id;
        $this->d->admin->deleteTrigger(JsonIo::access($request)->actor, $id);

        return JsonIo::empty($response);
    }

    /**
     * «Jetzt prüfen»: Anzahl Treffer, startet nichts.
     *
     * @param array<string, string> $args
     */
    public function checkTrigger(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $trigger = $this->trigger(JsonIo::arg($args, 'id'));

        return JsonIo::json($response, ['id' => $trigger->id, 'matches' => $this->d->admin->checkTrigger($trigger)]);
    }

    // ------------------------------------------------------------ Protokoll

    public function audit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $entries = $this->d->audit->entries(
            JsonIo::query($request, 'type'),
            JsonIo::query($request, 'id'),
            JsonIo::queryInt($request, 'limit', 100, 1, 500),
        );

        return JsonIo::json($response, [
            'entries' => array_map(static fn (AuditEntry $e): array => [
                'at' => JsonIo::time($e->at),
                'actor' => ['id' => $e->actor->id, 'name' => $e->actor->name],
                'action' => $e->action,
                'objectType' => $e->objectType,
                'objectId' => $e->objectId,
                'detail' => (object) $e->detail,
            ], $entries),
        ]);
    }

    // ------------------------------------------------------------ Hilfen

    private function connection(string $id): RestConnection
    {
        return $this->d->connections->findConnection($id) ?? throw new NotFoundException("Verbindung '{$id}' nicht gefunden.");
    }

    private function model(string $name): RestModelSpec
    {
        return $this->d->models->findModel($name) ?? throw new NotFoundException("Datenmodell '{$name}' nicht gefunden.");
    }

    private function trigger(string $id): AdminTrigger
    {
        return $this->d->triggers->findTrigger($id) ?? throw new NotFoundException("Trigger '{$id}' nicht gefunden.");
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function connectionInput(string $id, array $body): RestConnection
    {
        $auth = JsonIo::string($body, 'authType') ?? AuthType::None->value;

        return new RestConnection(
            $id,
            JsonIo::string($body, 'name') ?? '',
            JsonIo::string($body, 'baseUrl') ?? '',
            AuthType::tryFrom($auth) ?? throw RequestValidationException::field('authType', "Unbekannte Anmeldung '{$auth}'."),
            JsonIo::string($body, 'authHeader'),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function secret(array $body): ?string
    {
        $secret = $body['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
