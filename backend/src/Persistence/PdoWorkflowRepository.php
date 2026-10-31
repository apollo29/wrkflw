<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Contracts\DefinitionRepositoryInterface;
use WorkflowEngine\Contracts\InstanceQueryInterface;
use WorkflowEngine\Contracts\InstanceQueueInterface;
use WorkflowEngine\Contracts\ModelUsageQueryInterface;
use WorkflowEngine\Contracts\TemplateUsage;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionStatus;
use WorkflowEngine\Definition\DefinitionStructure;
use WorkflowEngine\Definition\DefinitionSummary;
use WorkflowEngine\Definition\SavedVersion;
use WorkflowEngine\Definition\VersionDeleteBlock;
use WorkflowEngine\Definition\VersionSummary;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SystemClock;
use WorkflowEngine\Exception\InvalidDefinitionException;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\HistoryEntry;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\InstanceFilter;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\InstanceSummary;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Model\ModelUsage;

/**
 * Ablage von Definitionen, Durchläufen und Verlauf in MariaDB (Schema `schema.sql`).
 *
 * Zeitpunkte stehen als DATETIME in der Zeitzone der Uhr, wie in 2.x. Alle Zeitpunkte
 * (auch `updated_at` und der Verlauf) kommen aus der Uhr, damit Tests und Lease
 * deterministisch sind.
 */
final readonly class PdoWorkflowRepository implements
    WorkflowRepositoryInterface,
    DefinitionRepositoryInterface,
    InstanceQueryInterface,
    InstanceQueueInterface,
    ModelUsageQueryInterface
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /** Status, die als «laufend» zählen; abgebrochene zählen als beendet (9.14). */
    private const string RUNNING_SQL = "status IN ('running', 'waiting_event', 'waiting_timer')";

    public function __construct(
        private \PDO $pdo,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    // ---------------------------------------------------------------- Definitionen (Engine)

    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition
    {
        if ($version === null) {
            $row = $this->fetchOne(
                'SELECT version, status, definition FROM wf_definition WHERE id = ? ORDER BY version DESC LIMIT 1',
                [$id],
            );
            if ($row === null || self::str($row, 'status') !== DefinitionStatus::Active->value) {
                throw new NotFoundException($row === null
                    ? "Workflow '{$id}' nicht gefunden."
                    : "Workflow '{$id}' ist nicht aktiv und lässt sich nicht starten.");
            }
        } else {
            $row = $this->fetchOne('SELECT version, definition FROM wf_definition WHERE id = ? AND version = ?', [$id, $version])
                ?? throw NotFoundException::definition($id, $version);
        }

        return $this->parse($id, $row);
    }

    // ---------------------------------------------------------------- Definitionen (Verwaltung)

    public function save(WorkflowDefinition $definition, DefinitionStatus $status): SavedVersion
    {
        $data = $definition->toArray();
        unset($data['version']);
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = $this->now();

        return $this->transaction(function () use ($definition, $status, $json, $now): SavedVersion {
            $current = $this->fetchOne(
                'SELECT version FROM wf_definition WHERE id = ? ORDER BY version DESC LIMIT 1 FOR UPDATE',
                [$definition->id],
            );
            $currentVersion = $current === null ? null : self::int($current, 'version');

            $overwrite = $currentVersion !== null
                && $status !== DefinitionStatus::Active
                && $this->countInstances($definition->id, $currentVersion) === 0;

            if ($overwrite) {
                $this->execute(
                    'UPDATE wf_definition SET name = ?, definition = ?, status = ?, updated_at = ? WHERE id = ? AND version = ?',
                    [$definition->name, $json, $status->value, $now, $definition->id, $currentVersion],
                );

                return new SavedVersion($definition->id, (int) $currentVersion, $status, false);
            }

            $version = ($currentVersion ?? 0) + 1;
            $this->execute('UPDATE wf_definition SET active = 0 WHERE id = ?', [$definition->id]);
            $this->execute(
                'INSERT INTO wf_definition (id, version, name, definition, active, status, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
                [$definition->id, $version, $definition->name, $json, $status->value, $now, $now],
            );

            return new SavedVersion($definition->id, $version, $status, true);
        });
    }

    public function listDefinitions(): array
    {
        $rows = $this->fetchAll(
            'SELECT d.id, d.version, d.name, d.status, d.definition, d.updated_at,
                    (SELECT COUNT(*) FROM wf_definition o WHERE o.id = d.id AND o.version < d.version) AS older,
                    (SELECT COUNT(*) FROM wf_instance i WHERE i.definition_id = d.id) AS total,
                    (SELECT COUNT(*) FROM wf_instance i WHERE i.definition_id = d.id AND i.' . self::RUNNING_SQL . ') AS running
             FROM wf_definition d
             WHERE d.version = (SELECT MAX(m.version) FROM wf_definition m WHERE m.id = d.id)
             ORDER BY d.name, d.id',
        );

        $out = [];
        foreach ($rows as $row) {
            $id = self::str($row, 'id');
            try {
                $structure = DefinitionStructure::of($this->parse($id, $row));
            } catch (InvalidDefinitionException) {
                $structure = null;
            }
            $out[] = new DefinitionSummary(
                id: $id,
                name: self::str($row, 'name'),
                version: self::int($row, 'version'),
                status: self::status($row),
                updatedAt: $this->time(self::str($row, 'updated_at')),
                runningInstances: self::int($row, 'running'),
                totalInstances: self::int($row, 'total'),
                olderVersions: self::int($row, 'older'),
                structure: $structure,
            );
        }

        return $out;
    }

    public function versions(string $id): array
    {
        $rows = $this->fetchAll(
            'SELECT d.version, d.name, d.status, d.created_at, d.updated_at,
                    (SELECT COUNT(*) FROM wf_instance i WHERE i.definition_id = d.id AND i.definition_ver = d.version) AS total,
                    (SELECT COUNT(*) FROM wf_instance i WHERE i.definition_id = d.id AND i.definition_ver = d.version AND i.' . self::RUNNING_SQL . ') AS running
             FROM wf_definition d WHERE d.id = ? ORDER BY d.version DESC',
            [$id],
        );

        $out = [];
        foreach ($rows as $index => $row) {
            $total = self::int($row, 'total');
            $out[] = new VersionSummary(
                version: self::int($row, 'version'),
                name: self::str($row, 'name'),
                status: self::status($row),
                createdAt: $this->time(self::str($row, 'created_at')),
                updatedAt: $this->time(self::str($row, 'updated_at')),
                runningInstances: self::int($row, 'running'),
                totalInstances: $total,
                deleteBlockedBy: $index === 0 ? VersionDeleteBlock::Latest : ($total > 0 ? VersionDeleteBlock::Referenced : null),
            );
        }

        return $out;
    }

    public function deleteVersion(string $id, int $version): ?VersionDeleteBlock
    {
        // Prüfen und löschen in einer Transaktion mit Sperre auf der neuesten Zeile: sonst
        // könnte dazwischen eine neue Version entstehen (aus 2.x übernommen).
        return $this->transaction(function () use ($id, $version): ?VersionDeleteBlock {
            $newest = $this->fetchOne('SELECT version FROM wf_definition WHERE id = ? ORDER BY version DESC LIMIT 1 FOR UPDATE', [$id]);
            $exists = $this->fetchOne('SELECT version FROM wf_definition WHERE id = ? AND version = ?', [$id, $version]);
            if ($newest === null || $exists === null) {
                return VersionDeleteBlock::NotFound;
            }
            if ($version >= self::int($newest, 'version')) {
                return VersionDeleteBlock::Latest;
            }
            if ($this->countInstances($id, $version) > 0) {
                return VersionDeleteBlock::Referenced;
            }
            $this->execute('DELETE FROM wf_definition WHERE id = ? AND version = ?', [$id, $version]);

            return null;
        });
    }

    public function findJson(string $id, ?int $version = null): ?string
    {
        $row = $version === null
            ? $this->fetchOne('SELECT definition FROM wf_definition WHERE id = ? ORDER BY version DESC LIMIT 1', [$id])
            : $this->fetchOne('SELECT definition FROM wf_definition WHERE id = ? AND version = ?', [$id, $version]);

        return $row === null ? null : self::str($row, 'definition');
    }

    public function templateUsage(string $templateId): array
    {
        $usage = [];
        foreach ($this->fetchAll('SELECT id, version, definition FROM wf_definition ORDER BY id, version') as $row) {
            $data = json_decode(self::str($row, 'definition'), true);
            $steps = is_array($data) ? ($data['steps'] ?? null) : null;
            if (!is_array($steps)) {
                continue;
            }
            foreach ($steps as $name => $step) {
                if (!is_array($step)) {
                    continue;
                }
                $config = is_array($step['config'] ?? null) ? $step['config'] : [];
                $ui = is_array($step['ui'] ?? null) ? $step['ui'] : [];
                if (($config['templateId'] ?? null) === $templateId || ($ui['template'] ?? null) === $templateId) {
                    $usage[] = new TemplateUsage(self::str($row, 'id'), self::int($row, 'version'), (string) $name);
                }
            }
        }

        return $usage;
    }

    public function modelUsage(string $model): array
    {
        $usage = [];
        foreach ($this->fetchAll('SELECT id, version, definition FROM wf_definition ORDER BY id, version') as $row) {
            $data = json_decode(self::str($row, 'definition'), true);
            $steps = is_array($data) ? ($data['steps'] ?? null) : null;
            if (is_array($steps)) {
                array_push($usage, ...ModelUsage::scan($model, self::str($row, 'id'), self::int($row, 'version'), $steps));
            }
        }

        return $usage;
    }

    // ---------------------------------------------------------------- Durchläufe

    public function saveInstance(WorkflowInstance $instance): void
    {
        $now = $this->now();
        $this->execute(
            'INSERT INTO wf_instance
                (id, definition_id, definition_ver, current_step, status, context, wake_at, attempts,
                 subject_type, subject_id, last_error, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                current_step = VALUES(current_step), status = VALUES(status), context = VALUES(context),
                wake_at = VALUES(wake_at), attempts = VALUES(attempts), last_error = VALUES(last_error),
                updated_at = VALUES(updated_at)',
            [
                $instance->id,
                $instance->definitionId,
                $instance->definitionVersion,
                $instance->currentStep,
                $instance->status->value,
                json_encode($instance->context === [] ? new \stdClass() : $instance->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $instance->wakeAt?->setTimezone($this->clock->now()->getTimezone())->format(self::FORMAT),
                $instance->attempts,
                $instance->subjectType,
                $instance->subjectId,
                $instance->lastError,
                $now,
                $now,
            ],
        );
    }

    public function findInstance(string $id): ?WorkflowInstance
    {
        $row = $this->fetchOne('SELECT * FROM wf_instance WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function logHistory(string $instanceId, HistoryKind $kind, ?string $step, array $detail = []): void
    {
        $this->execute(
            'INSERT INTO wf_history (instance_id, kind, step, detail, created_at) VALUES (?, ?, ?, ?, ?)',
            [$instanceId, $kind->value, $step, json_encode($detail === [] ? new \stdClass() : $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $this->now()],
        );
    }

    public function findHistory(string $instanceId): array
    {
        $entries = [];
        foreach ($this->fetchAll('SELECT kind, step, detail, created_at FROM wf_history WHERE instance_id = ? ORDER BY id', [$instanceId]) as $row) {
            $kind = HistoryKind::tryFrom(self::str($row, 'kind'));
            if ($kind === null) {
                continue; // Verlaufsarten aus 2.x, die v3 nicht mehr schreibt (z. B. enter_step)
            }
            $detail = is_string($row['detail'] ?? null) ? json_decode($row['detail'], true) : [];
            $entries[] = new HistoryEntry(
                $kind,
                is_string($row['step'] ?? null) ? $row['step'] : null,
                self::stringKeys(is_array($detail) ? $detail : []),
                $this->time(self::str($row, 'created_at')),
            );
        }

        return $entries;
    }

    public function listInstances(InstanceFilter $filter): array
    {
        $where = [];
        $params = [];
        if ($filter->status !== null) {
            $where[] = 'i.status = ?';
            $params[] = $filter->status->value;
        }
        if ($filter->definitionId !== null) {
            $where[] = 'i.definition_id = ?';
            $params[] = $filter->definitionId;
        }
        if ($filter->subjectType !== null) {
            $where[] = 'i.subject_type = ?';
            $params[] = $filter->subjectType;
        }
        if ($filter->subjectId !== null) {
            $where[] = 'i.subject_id = ?';
            $params[] = $filter->subjectId;
        }

        $rows = $this->fetchAll(sprintf(
            'SELECT i.*, COALESCE(d.name, i.definition_id) AS definition_name
             FROM wf_instance i
             LEFT JOIN wf_definition d ON d.id = i.definition_id AND d.version = i.definition_ver
             %s ORDER BY i.updated_at DESC, i.id DESC LIMIT %d OFFSET %d',
            $where === [] ? '' : 'WHERE ' . implode(' AND ', $where),
            max(1, min($filter->limit, 500)),
            max(0, $filter->offset),
        ), $params);

        return array_map(fn (array $row): InstanceSummary => new InstanceSummary(
            id: self::str($row, 'id'),
            definitionId: self::str($row, 'definition_id'),
            definitionVersion: self::int($row, 'definition_ver'),
            definitionName: self::str($row, 'definition_name'),
            currentStep: self::str($row, 'current_step'),
            status: $this->instanceStatus($row),
            subjectType: is_string($row['subject_type'] ?? null) ? $row['subject_type'] : null,
            subjectId: is_string($row['subject_id'] ?? null) ? $row['subject_id'] : null,
            wakeAt: is_string($row['wake_at'] ?? null) ? $this->time($row['wake_at']) : null,
            lastError: is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
            createdAt: $this->time(self::str($row, 'created_at')),
            updatedAt: $this->time(self::str($row, 'updated_at')),
        ), $rows);
    }

    public function countByStatus(?string $definitionId = null): array
    {
        $counts = [];
        foreach (InstanceStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        $rows = $definitionId === null
            ? $this->fetchAll('SELECT status, COUNT(*) AS n FROM wf_instance GROUP BY status')
            : $this->fetchAll('SELECT status, COUNT(*) AS n FROM wf_instance WHERE definition_id = ? GROUP BY status', [$definitionId]);
        foreach ($rows as $row) {
            $status = InstanceStatus::tryFrom(self::str($row, 'status'));
            if ($status !== null) {
                $counts[$status->value] = self::int($row, 'n');
            }
        }

        return $counts;
    }

    public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array
    {
        $nowSql = $now->setTimezone($this->clock->now()->getTimezone())->format(self::FORMAT);
        $stale = $now->modify("-{$leaseSeconds} seconds")->setTimezone($this->clock->now()->getTimezone())->format(self::FORMAT);

        return $this->transaction(function () use ($nowSql, $stale, $limit): array {
            // Gesperrte Zeilen anderer Läufe überspringen (ADR 4).
            $rows = $this->fetchAll(
                'SELECT * FROM wf_instance
                 WHERE (status = ? AND wake_at IS NOT NULL AND wake_at <= ?)
                    OR (status = ? AND updated_at <= ?)
                 ORDER BY wake_at IS NULL, wake_at, updated_at
                 LIMIT ' . max(1, $limit) . '
                 FOR UPDATE SKIP LOCKED',
                [InstanceStatus::WaitingTimer->value, $nowSql, InstanceStatus::Running->value, $stale],
            );

            $instances = [];
            foreach ($rows as $row) {
                $instance = $this->hydrate($row);
                $this->execute('UPDATE wf_instance SET status = ?, updated_at = ? WHERE id = ?', [InstanceStatus::Running->value, $nowSql, $instance->id]);
                $instances[] = $instance;
            }

            return $instances;
        });
    }

    // ---------------------------------------------------------------- Hilfen

    private function countInstances(string $id, int $version): int
    {
        $row = $this->fetchOne('SELECT COUNT(*) AS n FROM wf_instance WHERE definition_id = ? AND definition_ver = ?', [$id, $version]);

        return $row === null ? 0 : self::int($row, 'n');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function parse(string $id, array $row): WorkflowDefinition
    {
        $data = json_decode(self::str($row, 'definition'), true);
        if (!is_array($data)) {
            throw InvalidDefinitionException::single("Die gespeicherte Definition von '{$id}' ist kein JSON-Objekt.");
        }
        $data['id'] ??= $id;

        return DefinitionParser::fromArray($data, self::int($row, 'version'));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WorkflowInstance
    {
        $context = json_decode(self::str($row, 'context'), true);

        return new WorkflowInstance(
            id: self::str($row, 'id'),
            definitionId: self::str($row, 'definition_id'),
            definitionVersion: self::int($row, 'definition_ver'),
            currentStep: self::str($row, 'current_step'),
            status: $this->instanceStatus($row),
            context: self::stringKeys(is_array($context) ? $context : []),
            wakeAt: is_string($row['wake_at'] ?? null) ? $this->time($row['wake_at']) : null,
            subjectType: is_string($row['subject_type'] ?? null) ? $row['subject_type'] : null,
            subjectId: is_string($row['subject_id'] ?? null) ? $row['subject_id'] : null,
            lastError: is_string($row['last_error'] ?? null) ? $row['last_error'] : null,
            attempts: self::int($row, 'attempts'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function instanceStatus(array $row): InstanceStatus
    {
        $raw = self::str($row, 'status');

        return InstanceStatus::tryFrom($raw)
            ?? throw new WorkflowException("Durchlauf '" . self::str($row, 'id') . "' hat den unbekannten Status '{$raw}'.");
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function status(array $row): DefinitionStatus
    {
        return DefinitionStatus::tryFrom(self::str($row, 'status')) ?? DefinitionStatus::Inactive;
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::FORMAT);
    }

    private function time(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, $this->clock->now()->getTimezone());
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function transaction(\Closure $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function execute(string $sql, array $params = []): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @param list<mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? self::stringKeys($row) : null;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params = []): array
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
    private static function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new WorkflowException("Spalte '{$key}' fehlt oder ist kein Text.");
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        throw new WorkflowException("Spalte '{$key}' fehlt oder ist keine Zahl.");
    }
}
