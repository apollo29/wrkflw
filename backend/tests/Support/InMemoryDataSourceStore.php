<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\AdminTriggerRepositoryInterface;
use WorkflowEngine\Contracts\AuditLogInterface;
use WorkflowEngine\Contracts\RestConnectionRepositoryInterface;
use WorkflowEngine\Contracts\RestModelRepositoryInterface;
use WorkflowEngine\Rest\AdminTrigger;
use WorkflowEngine\Rest\AuditEntry;
use WorkflowEngine\Rest\RestConnection;
use WorkflowEngine\Rest\RestModelSpec;

/** Verbindungen, REST-Modelle, Trigger und Protokoll im Arbeitsspeicher. */
final class InMemoryDataSourceStore implements
    RestConnectionRepositoryInterface,
    RestModelRepositoryInterface,
    AdminTriggerRepositoryInterface,
    AuditLogInterface
{
    /** @var array<string, RestConnection> */
    private array $connections = [];

    /** @var array<string, RestModelSpec> */
    private array $models = [];

    /** @var array<string, AdminTrigger> */
    private array $triggers = [];

    /** @var list<AuditEntry> */
    public array $audit = [];

    public function findConnection(string $id): ?RestConnection
    {
        return $this->connections[$id] ?? null;
    }

    public function allConnections(): array
    {
        return array_values($this->connections);
    }

    public function saveConnection(RestConnection $connection): void
    {
        $this->connections[$connection->id] = $connection;
    }

    public function deleteConnection(string $id): void
    {
        unset($this->connections[$id]);
    }

    public function findModel(string $name): ?RestModelSpec
    {
        return $this->models[$name] ?? null;
    }

    public function allModels(): array
    {
        return array_values($this->models);
    }

    public function saveModel(RestModelSpec $model): void
    {
        $this->models[$model->name] = $model;
    }

    public function deleteModel(string $name): void
    {
        unset($this->models[$name]);
    }

    public function findTrigger(string $id): ?AdminTrigger
    {
        return $this->triggers[$id] ?? null;
    }

    public function allTriggers(): array
    {
        return array_values($this->triggers);
    }

    public function saveTrigger(AdminTrigger $trigger): void
    {
        $this->triggers[$trigger->id] = $trigger;
    }

    public function deleteTrigger(string $id): void
    {
        unset($this->triggers[$id]);
    }

    public function markPolled(string $id, \DateTimeImmutable $at): void
    {
        $t = $this->triggers[$id] ?? null;
        if ($t !== null) {
            $this->triggers[$id] = new AdminTrigger($t->id, $t->name, $t->model, $t->conditions, $t->definitionId, $t->inputs, $t->intervalMinutes, $t->lockDays, $t->active, $at);
        }
    }

    public function record(AuditEntry $entry): void
    {
        $this->audit[] = $entry;
    }

    public function entries(?string $objectType = null, ?string $objectId = null, int $limit = 100): array
    {
        $found = array_values(array_filter(
            array_reverse($this->audit),
            static fn (AuditEntry $e): bool => ($objectType === null || $e->objectType === $objectType) && ($objectId === null || $e->objectId === $objectId),
        ));

        return array_slice($found, 0, $limit);
    }
}
