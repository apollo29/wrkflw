<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Default-Repository auf Basis von PDO (MariaDB).
 * Definition und Kontext werden als JSON gespeichert.
 */
final class PdoWorkflowRepository implements WorkflowRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition
    {
        if ($version === null) {
            $stmt = $this->pdo->prepare(
                'SELECT version, definition FROM wf_definition
                 WHERE id = :id AND active = 1
                 ORDER BY version DESC LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT version, definition FROM wf_definition WHERE id = :id AND version = :v'
            );
            $stmt->execute([':id' => $id, ':v' => $version]);
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new WorkflowException("Workflow-Definition '{$id}' nicht gefunden.");
        }

        // id und version sind in der DB massgeblich und werden in die JSON-Daten injiziert.
        $data = $this->decodeObject($this->reqString($row, 'definition'));
        $data['id'] ??= $id;
        $data['version'] = $this->reqInt($row, 'version');

        return WorkflowDefinition::fromArray($data);
    }

    public function listDefinitions(): array
    {
        $stmt = $this->pdo->query('SELECT id, version, name, active FROM wf_definition ORDER BY id ASC, version ASC');
        if ($stmt === false) {
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => $this->reqString($row, 'id'),
                'version' => $this->reqInt($row, 'version'),
                'name' => $this->reqString($row, 'name'),
                'active' => $this->reqInt($row, 'active') === 1,
            ];
        }

        return $out;
    }

    public function findDefinitionJson(string $id, ?int $version = null): ?string
    {
        if ($version === null) {
            $stmt = $this->pdo->prepare(
                'SELECT definition FROM wf_definition
                 WHERE id = :id AND active = 1 ORDER BY version DESC LIMIT 1'
            );
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT definition FROM wf_definition WHERE id = :id AND version = :v'
            );
            $stmt->execute([':id' => $id, ':v' => $version]);
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->reqString($row, 'definition') : null;
    }

    public function saveDefinition(string $id, string $name, string $json, bool $activate = true): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM wf_definition WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $next = $stmt->fetchColumn();
            $version = is_numeric($next) ? (int) $next : 1;

            if ($activate) {
                $this->pdo->prepare('UPDATE wf_definition SET active = 0 WHERE id = :id')->execute([':id' => $id]);
            }

            $this->pdo->prepare(
                'INSERT INTO wf_definition (id, version, name, definition, active)
                 VALUES (:id, :v, :name, :def, :active)'
            )->execute([
                ':id' => $id,
                ':v' => $version,
                ':name' => $name,
                ':def' => $json,
                ':active' => $activate ? 1 : 0,
            ]);

            $this->pdo->commit();

            return $version;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function saveInstance(WorkflowInstance $i): void
    {
        $sql = 'INSERT INTO wf_instance
                  (id, definition_id, definition_ver, current_step, status, context,
                   wake_at, attempts, subject_type, subject_id, last_error)
                VALUES
                  (:id, :did, :dver, :step, :status, :context,
                   :wake, :attempts, :stype, :sid, :err)
                ON DUPLICATE KEY UPDATE
                   current_step = VALUES(current_step),
                   status       = VALUES(status),
                   context      = VALUES(context),
                   wake_at      = VALUES(wake_at),
                   attempts     = VALUES(attempts),
                   last_error   = VALUES(last_error)';

        $this->pdo->prepare($sql)->execute([
            ':id' => $i->id,
            ':did' => $i->definitionId,
            ':dver' => $i->definitionVersion,
            ':step' => $i->currentStep,
            ':status' => $i->status,
            ':context' => json_encode($i->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ':wake' => $i->wakeAt?->format('Y-m-d H:i:s'),
            ':attempts' => $i->attempts,
            ':stype' => $i->subjectType,
            ':sid' => $i->subjectId,
            ':err' => $i->lastError,
        ]);
    }

    public function findInstance(string $id): ?WorkflowInstance
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wf_instance WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findDueInstances(\DateTimeImmutable $now, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM wf_instance
             WHERE status = :st AND wake_at IS NOT NULL AND wake_at <= :now
             ORDER BY wake_at ASC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([
            ':st' => WorkflowInstance::WAITING_TIMER,
            ':now' => $now->format('Y-m-d H:i:s'),
        ]);

        $instances = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $instances[] = $this->hydrate($row);
            }
        }

        return $instances;
    }

    /**
     * @return list<WorkflowInstance>
     */
    public function claimDueInstances(\DateTimeImmutable $now, int $limit = 50, int $staleAfterSeconds = 0): array
    {
        $nowSql = $now->format('Y-m-d H:i:s');

        // Faellige Timer-Instanzen ...
        $where = '(status = :timer AND wake_at IS NOT NULL AND wake_at <= :now)';
        $params = [':timer' => WorkflowInstance::WAITING_TIMER, ':now' => $nowSql];

        // ... und optional haengende RUNNING-Instanzen (Lease abgelaufen).
        if ($staleAfterSeconds > 0) {
            $where .= ' OR (status = :running AND updated_at <= :stale)';
            $params[':running'] = WorkflowInstance::RUNNING;
            $params[':stale'] = $now->modify("-{$staleAfterSeconds} seconds")->format('Y-m-d H:i:s');
        }

        $this->pdo->beginTransaction();
        try {
            // Zeilen sperren; bereits gesperrte (von anderen Workern) ueberspringen.
            $select = $this->pdo->prepare(
                "SELECT * FROM wf_instance
                 WHERE {$where}
                 ORDER BY wake_at ASC
                 LIMIT " . max(1, $limit) . '
                 FOR UPDATE SKIP LOCKED'
            );
            $select->execute($params);

            // updated_at explizit erneuern (Lease), auch wenn der Status bereits running ist.
            $claim = $this->pdo->prepare(
                'UPDATE wf_instance SET status = :run, updated_at = :now WHERE id = :id'
            );

            $instances = [];
            foreach ($select->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $instance = $this->hydrate($row);
                $claim->execute([':run' => WorkflowInstance::RUNNING, ':now' => $nowSql, ':id' => $instance->id]);
                $instance->status = WorkflowInstance::RUNNING;
                $instances[] = $instance;
            }

            $this->pdo->commit();

            return $instances;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function logHistory(string $instanceId, string $kind, ?string $step, array $detail = []): void
    {
        $sql = 'INSERT INTO wf_history (instance_id, kind, step, detail)
                VALUES (:iid, :kind, :step, :detail)';
        $this->pdo->prepare($sql)->execute([
            ':iid' => $instanceId,
            ':kind' => $kind,
            ':step' => $step,
            ':detail' => $detail === []
                ? null
                : json_encode($detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function findHistory(string $instanceId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT kind, step, detail, created_at FROM wf_history
             WHERE instance_id = :id ORDER BY id ASC'
        );
        $stmt->execute([':id' => $instanceId]);

        $entries = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $detailRaw = $row['detail'] ?? null;
            $detail = is_string($detailRaw) && $detailRaw !== '' ? $this->decodeObject($detailRaw) : [];

            $entries[] = [
                'kind' => $this->reqString($row, 'kind'),
                'step' => $this->nullableString($row, 'step'),
                'detail' => $detail,
                'createdAt' => $this->reqString($row, 'created_at'),
            ];
        }

        return $entries;
    }

    public function findTemplateUsage(string $templateId): array
    {
        $stmt = $this->pdo->query('SELECT id, version, definition FROM wf_definition');
        if ($stmt === false) {
            return [];
        }

        $usage = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $data = $this->decodeObject($this->reqString($row, 'definition'));
            $steps = $data['steps'] ?? null;
            if (!is_array($steps)) {
                continue;
            }
            foreach ($steps as $stepName => $step) {
                if (!is_array($step)) {
                    continue;
                }
                $config = $step['config'] ?? null;
                if (is_array($config) && ($config['templateId'] ?? null) === $templateId) {
                    $usage[] = [
                        'definitionId' => $this->reqString($row, 'id'),
                        'version' => $this->reqInt($row, 'version'),
                        'step' => (string) $stepName,
                    ];
                }
            }
        }

        return $usage;
    }

    /**
     * @param array<mixed,mixed> $row
     */
    private function hydrate(array $row): WorkflowInstance
    {
        $wake = $row['wake_at'] ?? null;

        return new WorkflowInstance(
            id: $this->reqString($row, 'id'),
            definitionId: $this->reqString($row, 'definition_id'),
            definitionVersion: $this->reqInt($row, 'definition_ver'),
            currentStep: $this->reqString($row, 'current_step'),
            status: $this->reqString($row, 'status'),
            context: $this->decodeObject($this->reqString($row, 'context')),
            wakeAt: is_string($wake) ? new \DateTimeImmutable($wake) : null,
            subjectType: $this->nullableString($row, 'subject_type'),
            subjectId: $this->nullableString($row, 'subject_id'),
            lastError: $this->nullableString($row, 'last_error'),
            attempts: $this->reqInt($row, 'attempts'),
        );
    }

    /**
     * Dekodiert ein JSON-Objekt zu einem assoziativen Array mit String-Keys.
     *
     * @return array<string,mixed>
     */
    private function decodeObject(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new WorkflowException('Erwartetes JSON-Objekt, anderer Typ erhalten.');
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<mixed,mixed> $row
     */
    private function reqString(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new WorkflowException("Spalte '{$key}' fehlt oder ist kein String.");
        }

        return $value;
    }

    /**
     * @param array<mixed,mixed> $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new WorkflowException("Spalte '{$key}' ist kein String.");
        }

        return $value;
    }

    /**
     * @param array<mixed,mixed> $row
     */
    private function reqInt(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new WorkflowException("Spalte '{$key}' ist keine Ganzzahl.");
    }
}
