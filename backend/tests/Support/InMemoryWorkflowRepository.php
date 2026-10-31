<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Contracts\InstanceQueueInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\HistoryEntry;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Ablage im Arbeitsspeicher für Engine-Unit-Tests.
 *
 * Durchläufe werden beim Speichern und Laden kopiert, wie bei einer echten Datenbank:
 * eine Änderung, die nicht gespeichert wurde, fällt im Test auf.
 */
final class InMemoryWorkflowRepository implements WorkflowRepositoryInterface, InstanceQueueInterface
{
    /** @var array<string, array<int, WorkflowDefinition>> */
    private array $definitions = [];

    /** @var array<string, WorkflowInstance> */
    private array $instances = [];

    /** @var array<string, list<HistoryEntry>> */
    private array $history = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $updatedAt = [];

    public function __construct(private readonly ClockInterface $clock = new FrozenClock())
    {
    }

    public function addDefinition(WorkflowDefinition $definition): void
    {
        $this->definitions[$definition->id][$definition->version] = $definition;
        ksort($this->definitions[$definition->id]);
    }

    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition
    {
        $versions = $this->definitions[$id] ?? [];
        if ($version === null) {
            $latest = array_key_last($versions);

            return $latest === null ? throw NotFoundException::definition($id) : $versions[$latest];
        }

        return $versions[$version] ?? throw NotFoundException::definition($id, $version);
    }

    public function saveInstance(WorkflowInstance $instance): void
    {
        $this->instances[$instance->id] = clone $instance;
        $this->updatedAt[$instance->id] = $this->clock->now();
    }

    public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array
    {
        $stale = $now->modify("-{$leaseSeconds} seconds");
        $claimed = [];
        foreach ($this->instances as $id => $instance) {
            $due = ($instance->status === InstanceStatus::WaitingTimer && $instance->wakeAt !== null && $instance->wakeAt <= $now)
                || ($instance->status === InstanceStatus::Running && $this->updatedAt[$id] <= $stale);
            if (!$due || count($claimed) >= $limit) {
                continue;
            }
            $claimed[] = clone $instance;
            $instance->status = InstanceStatus::Running;
            $this->updatedAt[$id] = $now;
        }

        return $claimed;
    }

    public function findInstance(string $id): ?WorkflowInstance
    {
        return isset($this->instances[$id]) ? clone $this->instances[$id] : null;
    }

    public function logHistory(string $instanceId, HistoryKind $kind, ?string $step, array $detail = []): void
    {
        $this->history[$instanceId][] = new HistoryEntry($kind, $step, $detail, $this->clock->now());
    }

    public function findHistory(string $instanceId): array
    {
        return $this->history[$instanceId] ?? [];
    }

    /**
     * @return list<WorkflowInstance>
     */
    public function allInstances(): array
    {
        return array_values(array_map(static fn (WorkflowInstance $i): WorkflowInstance => clone $i, $this->instances));
    }

    public function lastHistory(string $instanceId): HistoryEntry
    {
        $history = $this->findHistory($instanceId);

        return $history[count($history) - 1] ?? throw new \LogicException('Kein Verlauf.');
    }

    /**
     * @return list<string>
     */
    public function historyKinds(string $instanceId): array
    {
        return array_map(static fn (HistoryEntry $e): string => $e->kind->value, $this->findHistory($instanceId));
    }
}
