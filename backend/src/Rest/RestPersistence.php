<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Contracts\PersistenceInterface;
use WorkflowEngine\Contracts\RestConnectionRepositoryInterface;
use WorkflowEngine\Contracts\RestModelRepositoryInterface;
use WorkflowEngine\Contracts\SecretStoreInterface;
use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Security\HttpResult;
use WorkflowEngine\Security\SafeHttpClient;

/**
 * Persistenz für REST-Datenmodelle (F-131). Pfade und Methoden kommen aus der
 * {@see RestModelSpec}, die Feldpfade aus dem Datenmodell; die Zugangsdaten aus der
 * Verbindung, entschlüsselt erst für die Kopfzeile.
 *
 * - 404 heisst «nicht gefunden» (F-124).
 * - 408, 425, 429 und 5xx sind technische Fehler und werden wiederholt (F-132).
 * - Andere 4xx und unbrauchbare Antworten sind dauerhafte Fehler.
 * - Suchen: Gleich-Bedingungen gehen als Query-Parameter mit, alle Bedingungen werden
 *   zusätzlich auf der Antwort geprüft; höchstens `maxResults` Treffer, erste Seite.
 */
final readonly class RestPersistence implements PersistenceInterface
{
    private RestClient $client;

    public function __construct(
        SafeHttpClient $http,
        private RestConnectionRepositoryInterface $connections,
        private RestModelRepositoryInterface $models,
        SecretStoreInterface $secrets,
        private RestOptions $options = new RestOptions(),
    ) {
        $this->client = new RestClient($http, $secrets);
    }

    public function load(DataModel $model, string $id): ?array
    {
        [$spec, $connection] = $this->resolve($model);
        $result = $this->client->send($connection, $spec, 'load', $id);
        if ($result->status === 404) {
            return null;
        }
        $item = $this->json($result, $model);
        if (!is_array($item)) {
            throw new RestRequestException("REST-Datenmodell '{$model->name}': Laden lieferte kein Objekt.");
        }

        $values = $this->fromItem($model, $item);
        $values[$model->idField] ??= $id;

        return $values;
    }

    public function find(DataModel $model, array $conditions, int $limit): array
    {
        [$spec, $connection] = $this->resolve($model);
        $query = [];
        foreach ($conditions as $condition) {
            if ($condition->operator === ConditionOperator::Eq && is_scalar($condition->value)) {
                $value = $condition->value;
                $query[$model->field($condition->field)->storageName()] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        $result = $this->client->send($connection, $spec, 'find', null, $query);
        if ($result->status === 404) {
            return [];
        }
        $body = $this->json($result, $model);
        $list = $spec->listPath === '' ? $body : self::get($body, $spec->listPath);
        if (!is_array($list) || !array_is_list($list)) {
            throw new RestRequestException("REST-Datenmodell '{$model->name}': die Trefferliste '{$spec->listPath}' fehlt in der Antwort.");
        }

        $rows = [];
        $max = min($limit, $this->options->maxResults);
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $values = $this->fromItem($model, $item);
            foreach ($conditions as $condition) {
                if (!$condition->matches($values[$condition->field] ?? null)) {
                    continue 2;
                }
            }
            $rows[] = $values;
            if (count($rows) >= $max) {
                break;
            }
        }

        return $rows;
    }

    public function update(DataModel $model, string $id, array $values, ChangeOrigin $origin): bool
    {
        [$spec, $connection] = $this->resolve($model);

        return $this->client->send($connection, $spec, 'update', $id, [], $this->toBody($model, $values))->status !== 404;
    }

    public function create(DataModel $model, ?string $id, array $values, ChangeOrigin $origin): string
    {
        [$spec, $connection] = $this->resolve($model);
        if ($id !== null) {
            $values = [$model->idField => $id] + $values;
        }
        $result = $this->client->send($connection, $spec, 'create', $id, [], $this->toBody($model, $values));

        $body = json_decode($result->body, true);
        $newId = is_array($body) ? self::get($body, $model->idFieldDefinition()->storageName()) : null;
        if (is_scalar($newId) && (string) $newId !== '') {
            return (string) $newId;
        }
        $location = $result->header('location');
        if ($location !== null && $location !== '') {
            return rawurldecode(basename((string) parse_url($location, PHP_URL_PATH)));
        }
        if ($id !== null) {
            return $id;
        }

        throw new RestRequestException("REST-Datenmodell '{$model->name}': Anlegen lieferte keine ID.");
    }

    /**
     * @return array{RestModelSpec, RestConnection}
     */
    private function resolve(DataModel $model): array
    {
        $spec = $this->models->findModel($model->name)
            ?? throw new RestRequestException("REST-Datenmodell '{$model->name}' ist nicht eingerichtet.");
        $connection = $this->connections->findConnection($spec->connectionId)
            ?? throw new RestRequestException("Verbindung '{$spec->connectionId}' für '{$model->name}' gibt es nicht.");

        return [$spec, $connection];
    }

    private function json(HttpResult $result, DataModel $model): mixed
    {
        try {
            return json_decode($result->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RestRequestException("REST-Datenmodell '{$model->name}': die Antwort ist kein gültiges JSON.");
        }
    }

    /**
     * @param array<mixed> $item
     *
     * @return array<string, mixed>
     */
    private function fromItem(DataModel $model, array $item): array
    {
        $values = [];
        foreach ($model->fields as $name => $field) {
            $value = self::get($item, $field->storageName());
            $values[$name] = is_scalar($value) || $value === null ? $value : null;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function toBody(DataModel $model, array $values): array
    {
        $body = [];
        foreach ($values as $name => $value) {
            $body = self::put($body, explode('.', $model->field($name)->storageName()), $value);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $segments
     *
     * @return array<string, mixed>
     */
    private static function put(array $data, array $segments, mixed $value): array
    {
        $head = array_shift($segments);
        if ($head === null) {
            return $data;
        }
        if ($segments === []) {
            $data[$head] = $value;

            return $data;
        }
        $child = $data[$head] ?? [];
        /** @var array<string, mixed> $child */
        $child = is_array($child) ? $child : [];
        $data[$head] = self::put($child, $segments, $value);

        return $data;
    }

    public static function get(mixed $data, string $path): mixed
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }
}
