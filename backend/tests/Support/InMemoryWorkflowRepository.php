<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * In-Memory-Fake des Repositories fuer schnelle Engine-Unit-Tests.
 * Haelt Definitionen, Instanzen und History im Speicher.
 */
final class InMemoryWorkflowRepository implements WorkflowRepositoryInterface
{
    /** @var array<string,array<int,WorkflowDefinition>> */
    private array $definitions = [];

    /** @var array<string,WorkflowInstance> */
    private array $instances = [];

    /** @var list<array{instanceId:string,kind:string,step:?string,detail:array<string,mixed>}> */
    private array $history = [];

    public function addDefinition(WorkflowDefinition $def): void
    {
        $this->definitions[$def->id][$def->version] = $def;
    }

    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition
    {
        $versions = $this->definitions[$id] ?? [];
        if ($versions === []) {
            throw new WorkflowException("Definition '{$id}' nicht gefunden.");
        }

        if ($version !== null) {
            return $versions[$version]
                ?? throw new WorkflowException("Definition '{$id}' v{$version} nicht gefunden.");
        }

        return $versions[max(array_keys($versions))];
    }

    public function saveInstance(WorkflowInstance $instance): void
    {
        $this->instances[$instance->id] = $instance;
    }

    public function findInstance(string $id): ?WorkflowInstance
    {
        return $this->instances[$id] ?? null;
    }

    public function findDueInstances(\DateTimeImmutable $now, int $limit = 50): array
    {
        $due = [];
        foreach ($this->instances as $instance) {
            if (count($due) >= $limit) {
                break;
            }
            if (
                $instance->status === WorkflowInstance::WAITING_TIMER
                && $instance->wakeAt !== null
                && $instance->wakeAt <= $now
            ) {
                $due[] = $instance;
            }
        }

        return $due;
    }

    public function claimDueInstances(\DateTimeImmutable $now, int $limit = 50): array
    {
        $claimed = [];
        foreach ($this->instances as $instance) {
            if (count($claimed) >= $limit) {
                break;
            }
            if (
                $instance->status === WorkflowInstance::WAITING_TIMER
                && $instance->wakeAt !== null
                && $instance->wakeAt <= $now
            ) {
                // Abholen = als laufend markieren, sodass ein zweiter Lauf sie nicht erneut erhaelt.
                $instance->status = WorkflowInstance::RUNNING;
                $claimed[] = $instance;
            }
        }

        return $claimed;
    }

    public function logHistory(string $instanceId, string $kind, ?string $step, array $detail = []): void
    {
        $this->history[] = [
            'instanceId' => $instanceId,
            'kind' => $kind,
            'step' => $step,
            'detail' => $detail,
        ];
    }

    public function findHistory(string $instanceId): array
    {
        $entries = [];
        foreach ($this->history as $h) {
            if ($h['instanceId'] === $instanceId) {
                $entries[] = [
                    'kind' => $h['kind'],
                    'step' => $h['step'],
                    'detail' => $h['detail'],
                    'createdAt' => '',
                ];
            }
        }

        return $entries;
    }

    /**
     * @return list<array{instanceId:string,kind:string,step:?string,detail:array<string,mixed>}>
     */
    public function history(): array
    {
        return $this->history;
    }

    /**
     * @return list<string> die History-Arten in Reihenfolge
     */
    public function historyKinds(): array
    {
        return array_map(static fn (array $h): string => $h['kind'], $this->history);
    }

    /**
     * @return list<WorkflowInstance>
     */
    public function allInstances(): array
    {
        return array_values($this->instances);
    }
}
