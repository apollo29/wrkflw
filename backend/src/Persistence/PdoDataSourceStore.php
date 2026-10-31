<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Contracts\AdminTriggerRepositoryInterface;
use WorkflowEngine\Contracts\AuditLogInterface;
use WorkflowEngine\Contracts\RestConnectionRepositoryInterface;
use WorkflowEngine\Contracts\RestModelRepositoryInterface;
use WorkflowEngine\Engine\SystemClock;
use WorkflowEngine\Rest\Actor;
use WorkflowEngine\Rest\AdminTrigger;
use WorkflowEngine\Rest\AuditEntry;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Rest\RestConnection;
use WorkflowEngine\Rest\RestModelSpec;

/**
 * Ablage der Datenquellen in MariaDB: Verbindungen (Zugangsdaten nur verschlüsselt),
 * REST-Datenmodelle, Admin-Trigger und das Protokoll (F-133 bis F-145).
 */
final readonly class PdoDataSourceStore implements
    RestConnectionRepositoryInterface,
    RestModelRepositoryInterface,
    AdminTriggerRepositoryInterface,
    AuditLogInterface
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private \PDO $pdo,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    // ------------------------------------------------------------ Verbindungen

    public function findConnection(string $id): ?RestConnection
    {
        $row = $this->one('SELECT * FROM wf_rest_connection WHERE id = ?', [$id]);

        return $row === null ? null : self::connection($row);
    }

    public function allConnections(): array
    {
        return array_map(self::connection(...), $this->all('SELECT * FROM wf_rest_connection ORDER BY name, id'));
    }

    public function saveConnection(RestConnection $connection): void
    {
        $now = $this->now();
        $this->exec(
            'INSERT INTO wf_rest_connection (id, name, base_url, auth_type, auth_header, secret, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), base_url = VALUES(base_url), auth_type = VALUES(auth_type),
                auth_header = VALUES(auth_header), secret = VALUES(secret), updated_at = VALUES(updated_at)',
            [$connection->id, $connection->name, $connection->baseUrl, $connection->authType->value, $connection->authHeader, $connection->encryptedSecret, $now, $now],
        );
    }

    public function deleteConnection(string $id): void
    {
        $this->exec('DELETE FROM wf_rest_connection WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------ Datenmodelle

    public function findModel(string $name): ?RestModelSpec
    {
        $row = $this->one('SELECT spec FROM wf_rest_model WHERE name = ?', [$name]);

        return $row === null ? null : self::spec($row);
    }

    public function allModels(): array
    {
        return array_map(self::spec(...), $this->all('SELECT spec FROM wf_rest_model ORDER BY name'));
    }

    public function saveModel(RestModelSpec $model): void
    {
        $this->exec(
            'INSERT INTO wf_rest_model (name, label, connection_id, spec, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE label = VALUES(label), connection_id = VALUES(connection_id), spec = VALUES(spec), updated_at = VALUES(updated_at)',
            [$model->name, $model->label, $model->connectionId, json_encode($model->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->now()],
        );
    }

    public function deleteModel(string $name): void
    {
        $this->exec('DELETE FROM wf_rest_model WHERE name = ?', [$name]);
    }

    // ------------------------------------------------------------ Trigger

    public function findTrigger(string $id): ?AdminTrigger
    {
        $row = $this->one('SELECT * FROM wf_trigger WHERE id = ?', [$id]);

        return $row === null ? null : $this->trigger($row);
    }

    public function allTriggers(): array
    {
        return array_map($this->trigger(...), $this->all('SELECT * FROM wf_trigger ORDER BY name, id'));
    }

    public function saveTrigger(AdminTrigger $trigger): void
    {
        $this->exec(
            'INSERT INTO wf_trigger (id, name, model, definition_id, conditions, inputs, interval_minutes, lock_days, active, last_polled_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), model = VALUES(model), definition_id = VALUES(definition_id),
                conditions = VALUES(conditions), inputs = VALUES(inputs), interval_minutes = VALUES(interval_minutes),
                lock_days = VALUES(lock_days), active = VALUES(active), last_polled_at = VALUES(last_polled_at), updated_at = VALUES(updated_at)',
            [
                $trigger->id,
                $trigger->name,
                $trigger->model,
                $trigger->definitionId,
                json_encode($trigger->conditionsToArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                json_encode($trigger->inputs === [] ? new \stdClass() : $trigger->inputs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $trigger->intervalMinutes,
                $trigger->lockDays,
                $trigger->active ? 1 : 0,
                $trigger->lastPolledAt?->setTimezone($this->clock->now()->getTimezone())->format(self::FORMAT),
                $this->now(),
            ],
        );
    }

    public function deleteTrigger(string $id): void
    {
        $this->exec('DELETE FROM wf_trigger WHERE id = ?', [$id]);
    }

    public function markPolled(string $id, \DateTimeImmutable $at): void
    {
        $this->exec('UPDATE wf_trigger SET last_polled_at = ? WHERE id = ?', [$at->setTimezone($this->clock->now()->getTimezone())->format(self::FORMAT), $id]);
    }

    // ------------------------------------------------------------ Protokoll

    public function record(AuditEntry $entry): void
    {
        $this->exec(
            'INSERT INTO wf_audit (at, actor_id, actor_name, action, object_type, object_id, detail) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $entry->at->setTimezone($this->clock->now()->getTimezone())->format(self::FORMAT),
                $entry->actor->id,
                $entry->actor->name,
                $entry->action,
                $entry->objectType,
                $entry->objectId,
                json_encode($entry->detail === [] ? new \stdClass() : $entry->detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    public function entries(?string $objectType = null, ?string $objectId = null, int $limit = 100): array
    {
        $where = [];
        $params = [];
        if ($objectType !== null) {
            $where[] = 'object_type = ?';
            $params[] = $objectType;
        }
        if ($objectId !== null) {
            $where[] = 'object_id = ?';
            $params[] = $objectId;
        }
        $rows = $this->all(
            'SELECT * FROM wf_audit' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY id DESC LIMIT ' . max(1, min($limit, 1000)),
            $params,
        );

        return array_map(function (array $row): AuditEntry {
            $detail = is_string($row['detail'] ?? null) ? json_decode($row['detail'], true) : [];

            return new AuditEntry(
                new \DateTimeImmutable(self::text($row, 'at'), $this->clock->now()->getTimezone()),
                new Actor(self::text($row, 'actor_id'), self::text($row, 'actor_name')),
                self::text($row, 'action'),
                self::text($row, 'object_type'),
                self::text($row, 'object_id'),
                self::stringKeys(is_array($detail) ? $detail : []),
            );
        }, $rows);
    }

    // ------------------------------------------------------------ Hilfen

    /**
     * @param array<string, mixed> $row
     */
    private static function connection(array $row): RestConnection
    {
        $header = $row['auth_header'] ?? null;
        $secret = $row['secret'] ?? null;

        return new RestConnection(
            self::text($row, 'id'),
            self::text($row, 'name'),
            self::text($row, 'base_url'),
            AuthType::tryFrom(self::text($row, 'auth_type')) ?? AuthType::None,
            is_string($header) ? $header : null,
            is_string($secret) ? $secret : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function spec(array $row): RestModelSpec
    {
        $data = json_decode(self::text($row, 'spec'), true);

        return RestModelSpec::fromArray(is_array($data) ? $data : []);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function trigger(array $row): AdminTrigger
    {
        $conditions = json_decode(self::text($row, 'conditions'), true);
        $inputs = json_decode(self::text($row, 'inputs'), true);
        $mapping = [];
        foreach (is_array($inputs) ? $inputs : [] as $input => $field) {
            if (is_string($field)) {
                $mapping[(string) $input] = $field;
            }
        }
        $polled = $row['last_polled_at'] ?? null;

        return new AdminTrigger(
            self::text($row, 'id'),
            self::text($row, 'name'),
            self::text($row, 'model'),
            AdminTrigger::conditionsFromArray(is_array($conditions) ? $conditions : []),
            self::text($row, 'definition_id'),
            $mapping,
            (int) self::text($row, 'interval_minutes'),
            (int) self::text($row, 'lock_days'),
            self::text($row, 'active') === '1',
            is_string($polled) ? new \DateTimeImmutable($polled, $this->clock->now()->getTimezone()) : null,
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::FORMAT);
    }

    /**
     * @param list<mixed> $params
     */
    private function exec(string $sql, array $params): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $params): ?array
    {
        $rows = $this->all($sql, $params);

        return $rows[0] ?? null;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = self::stringKeys($row);
            }
        }

        return $rows;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
