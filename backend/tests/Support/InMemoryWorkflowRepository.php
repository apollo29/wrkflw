<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

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
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\HistoryEntry;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\InstanceFilter;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\InstanceSummary;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Model\ModelUsage;

/**
 * Ablage im Arbeitsspeicher für Engine-Unit-Tests.
 *
 * Durchläufe werden beim Speichern und Laden kopiert, wie bei einer echten Datenbank:
 * eine Änderung, die nicht gespeichert wurde, fällt im Test auf. Verwaltung und Abfragen
 * folgen denselben Regeln wie `PdoWorkflowRepository` (dessen Integrationstests sind die
 * Referenz).
 */
final class InMemoryWorkflowRepository implements
    WorkflowRepositoryInterface,
    DefinitionRepositoryInterface,
    InstanceQueryInterface,
    InstanceQueueInterface,
    ModelUsageQueryInterface
{
    /** @var array<string, array<int, WorkflowDefinition>> */
    private array $definitions = [];

    /** @var array<string, array<int, array{status: DefinitionStatus, created: \DateTimeImmutable, updated: \DateTimeImmutable}>> */
    private array $meta = [];

    /** @var array<string, \DateTimeImmutable> */
    private array $createdAt = [];

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
        $this->put($definition, DefinitionStatus::Active);
    }

    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition
    {
        $versions = $this->definitions[$id] ?? [];
        if ($version === null) {
            $latest = array_key_last($versions);
            if ($latest === null) {
                throw NotFoundException::definition($id);
            }
            if ($this->meta[$id][$latest]['status'] !== DefinitionStatus::Active) {
                throw new NotFoundException("Workflow '{$id}' ist nicht aktiv und lässt sich nicht starten.");
            }

            return $versions[$latest];
        }

        return $versions[$version] ?? throw NotFoundException::definition($id, $version);
    }

    // ---------------------------------------------------------------- Verwaltung

    public function save(WorkflowDefinition $definition, DefinitionStatus $status): SavedVersion
    {
        $current = array_key_last($this->definitions[$definition->id] ?? []);
        if ($current !== null && $status !== DefinitionStatus::Active && $this->countInstances($definition->id, $current) === 0) {
            $this->put(DefinitionParser::fromArray($definition->toArray(), $current), $status);

            return new SavedVersion($definition->id, $current, $status, false);
        }
        $version = ($current ?? 0) + 1;
        $this->put(DefinitionParser::fromArray($definition->toArray(), $version), $status);

        return new SavedVersion($definition->id, $version, $status, true);
    }

    public function listDefinitions(): array
    {
        $out = [];
        foreach ($this->definitions as $id => $versions) {
            $id = (string) $id;
            $latest = (int) array_key_last($versions);
            $definition = $versions[$latest];
            $mine = array_filter($this->instances, static fn (WorkflowInstance $i): bool => $i->definitionId === $id);
            $out[] = new DefinitionSummary(
                id: $id,
                name: $definition->name,
                version: $latest,
                status: $this->meta[$id][$latest]['status'],
                updatedAt: $this->meta[$id][$latest]['updated'],
                runningInstances: count(array_filter($mine, static fn (WorkflowInstance $i): bool => !$i->status->isFinished())),
                totalInstances: count($mine),
                olderVersions: count($versions) - 1,
                structure: DefinitionStructure::of($definition),
                olderRunning: $this->olderRunning($id, $latest),
            );
        }
        usort($out, static fn (DefinitionSummary $a, DefinitionSummary $b): int => [$a->name, $a->id] <=> [$b->name, $b->id]);

        return $out;
    }

    public function versions(string $id): array
    {
        $out = [];
        $versions = array_reverse($this->definitions[$id] ?? [], true);
        $first = true;
        foreach ($versions as $version => $definition) {
            $mine = array_filter(
                $this->instances,
                static fn (WorkflowInstance $i): bool => $i->definitionId === $id && $i->definitionVersion === $version,
            );
            $total = count($mine);
            $out[] = new VersionSummary(
                version: $version,
                name: $definition->name,
                status: $this->meta[$id][$version]['status'],
                createdAt: $this->meta[$id][$version]['created'],
                updatedAt: $this->meta[$id][$version]['updated'],
                runningInstances: count(array_filter($mine, static fn (WorkflowInstance $i): bool => !$i->status->isFinished())),
                totalInstances: $total,
                deleteBlockedBy: $first ? VersionDeleteBlock::Latest : ($total > 0 ? VersionDeleteBlock::Referenced : null),
            );
            $first = false;
        }

        return $out;
    }

    public function deleteVersion(string $id, int $version): ?VersionDeleteBlock
    {
        $newest = array_key_last($this->definitions[$id] ?? []);
        if ($newest === null || !isset($this->definitions[$id][$version])) {
            return VersionDeleteBlock::NotFound;
        }
        if ($version >= $newest) {
            return VersionDeleteBlock::Latest;
        }
        if ($this->countInstances($id, $version) > 0) {
            return VersionDeleteBlock::Referenced;
        }
        unset($this->definitions[$id][$version], $this->meta[$id][$version]);

        return null;
    }

    public function findJson(string $id, ?int $version = null): ?string
    {
        $versions = $this->definitions[$id] ?? [];
        $definition = $version === null ? ($versions === [] ? null : $versions[(int) array_key_last($versions)]) : ($versions[$version] ?? null);
        if ($definition === null) {
            return null;
        }
        $data = $definition->toArray();
        unset($data['version']);

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function templateUsage(string $templateId): array
    {
        $usage = [];
        foreach ($this->definitions as $id => $versions) {
            foreach ($versions as $version => $definition) {
                foreach ($definition->steps as $name => $step) {
                    if (($step->config['templateId'] ?? null) === $templateId || ($step->ui['template'] ?? null) === $templateId) {
                        $usage[] = new TemplateUsage((string) $id, $version, (string) $name);
                    }
                }
            }
        }

        return $usage;
    }

    // ---------------------------------------------------------------- Abfragen

    public function listInstances(InstanceFilter $filter): array
    {
        $rows = array_filter($this->instances, static fn (WorkflowInstance $i): bool => ($filter->status === null || $i->status === $filter->status)
            && ($filter->definitionId === null || $i->definitionId === $filter->definitionId)
            && ($filter->subjectType === null || $i->subjectType === $filter->subjectType)
            && ($filter->subjectId === null || $i->subjectId === $filter->subjectId));
        uksort($rows, fn (string $a, string $b): int => [$this->updatedAt[$b], $b] <=> [$this->updatedAt[$a], $a]);
        $rows = array_slice(array_values($rows), max(0, $filter->offset), max(1, min($filter->limit, 500)));

        return array_map(fn (WorkflowInstance $i): InstanceSummary => new InstanceSummary(
            id: $i->id,
            definitionId: $i->definitionId,
            definitionVersion: $i->definitionVersion,
            definitionName: isset($this->definitions[$i->definitionId][$i->definitionVersion])
                ? $this->definitions[$i->definitionId][$i->definitionVersion]->name
                : $i->definitionId,
            currentStep: $i->currentStep,
            status: $i->status,
            subjectType: $i->subjectType,
            subjectId: $i->subjectId,
            wakeAt: $i->wakeAt,
            lastError: $i->lastError,
            createdAt: $this->createdAt[$i->id],
            updatedAt: $this->updatedAt[$i->id],
        ), $rows);
    }

    public function countByStatus(?string $definitionId = null): array
    {
        $counts = [];
        foreach (InstanceStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($this->instances as $instance) {
            if ($definitionId === null || $instance->definitionId === $definitionId) {
                ++$counts[$instance->status->value];
            }
        }

        return $counts;
    }

    /**
     * @return list<array{version: int, running: int}>
     */
    private function olderRunning(string $id, int $latest): array
    {
        $counts = [];
        foreach ($this->instances as $instance) {
            if ($instance->definitionId === $id && $instance->definitionVersion < $latest && !$instance->status->isFinished()) {
                $counts[$instance->definitionVersion] = ($counts[$instance->definitionVersion] ?? 0) + 1;
            }
        }
        krsort($counts);
        $out = [];
        foreach ($counts as $version => $running) {
            $out[] = ['version' => $version, 'running' => $running];
        }

        return $out;
    }

    private function put(WorkflowDefinition $definition, DefinitionStatus $status): void
    {
        $now = $this->clock->now();
        $this->definitions[$definition->id][$definition->version] = $definition;
        ksort($this->definitions[$definition->id]);
        $this->meta[$definition->id][$definition->version] = [
            'status' => $status,
            'created' => $this->meta[$definition->id][$definition->version]['created'] ?? $now,
            'updated' => $now,
        ];
    }

    private function countInstances(string $id, int $version): int
    {
        return count(array_filter(
            $this->instances,
            static fn (WorkflowInstance $i): bool => $i->definitionId === $id && $i->definitionVersion === $version,
        ));
    }

    public function modelUsage(string $model): array
    {
        $usage = [];
        foreach ($this->definitions as $id => $versions) {
            foreach ($versions as $version => $definition) {
                $steps = $definition->toArray()['steps'];
                array_push($usage, ...ModelUsage::scan($model, (string) $id, $version, is_array($steps) ? $steps : []));
            }
        }

        return $usage;
    }

    public function saveInstance(WorkflowInstance $instance): void
    {
        $this->instances[$instance->id] = clone $instance;
        $this->updatedAt[$instance->id] = $this->clock->now();
        $this->createdAt[$instance->id] ??= $this->clock->now();
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
