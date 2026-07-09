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

    /** @var array<string,array<int,array{name:string,active:bool,status:string,json:?string}>> */
    private array $definitionMeta = [];

    public function addDefinition(WorkflowDefinition $def): void
    {
        $this->definitions[$def->id][$def->version] = $def;
        $this->definitionMeta[$def->id][$def->version] = [
            'name' => $def->name,
            'active' => true,
            'status' => 'active',
            'json' => null,
        ];
    }

    public function listDefinitions(): array
    {
        $out = [];
        foreach ($this->definitionMeta as $id => $versions) {
            ksort($versions);
            foreach ($versions as $version => $meta) {
                $out[] = [
                    'id' => $id,
                    'version' => $version,
                    'name' => $meta['name'],
                    'active' => $meta['active'],
                    'status' => $meta['status'],
                ];
            }
        }

        return $out;
    }

    public function findDefinitionJson(string $id, ?int $version = null): ?string
    {
        $versions = $this->definitionMeta[$id] ?? [];
        if ($versions === []) {
            return null;
        }
        $version ??= max(array_keys($versions));

        return $versions[$version]['json'] ?? null;
    }

    public function saveDefinition(string $id, string $name, string $json, string $status = 'active'): int
    {
        $status = in_array($status, ['active', 'inactive', 'draft'], true) ? $status : 'active';

        if ($status === 'active') {
            $version = $this->nextVersion($id);
            foreach ($this->definitionMeta[$id] ?? [] as $v => $meta) {
                $meta['active'] = false;
                $this->definitionMeta[$id][$v] = $meta;
            }
            $this->store($id, $version, $name, $json, 'active');

            return $version;
        }

        // Entwurf/inaktiv: keine neue Version — die aktuelle in-place ueberschreiben.
        $version = $this->currentVersion($id) ?? $this->nextVersion($id);
        $this->store($id, $version, $name, $json, $status);

        return $version;
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

        // Ohne Version: nur die ausgelieferte (aktuell UND aktiv) Version.
        $served = null;
        foreach ($this->definitionMeta[$id] ?? [] as $v => $meta) {
            if ($meta['active'] && $meta['status'] === 'active') {
                $served = $served === null ? $v : max($served, $v);
            }
        }
        if ($served === null) {
            throw new WorkflowException("Definition '{$id}' nicht gefunden.");
        }

        return $versions[$served];
    }

    private function nextVersion(string $id): int
    {
        $existing = array_keys($this->definitions[$id] ?? []);

        return ($existing === [] ? 0 : max($existing)) + 1;
    }

    /** Die aktuelle (editierbare) Version einer id, oder null. */
    private function currentVersion(string $id): ?int
    {
        foreach ($this->definitionMeta[$id] ?? [] as $v => $meta) {
            if ($meta['active']) {
                return $v;
            }
        }

        return null;
    }

    private function store(string $id, int $version, string $name, string $json, string $status): void
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $data['id'] = $id;
        $data['version'] = $version;
        $this->definitions[$id][$version] = WorkflowDefinition::fromArray($data);
        $this->definitionMeta[$id][$version] = [
            'name' => $name,
            'active' => true,
            'status' => $status,
            'json' => $json,
        ];
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

    public function claimDueInstances(\DateTimeImmutable $now, int $limit = 50, int $staleAfterSeconds = 0): array
    {
        // Der Lease-Mechanismus (staleAfterSeconds) ist DB-spezifisch und wird hier
        // nicht simuliert; der Fake holt nur faellige Timer-Instanzen ab.
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

    public function findTemplateUsage(string $templateId): array
    {
        $usage = [];
        foreach ($this->definitions as $id => $versions) {
            foreach ($versions as $version => $def) {
                foreach ($def->steps as $name => $step) {
                    if (($step->config['templateId'] ?? null) === $templateId) {
                        $usage[] = ['definitionId' => $id, 'version' => $version, 'step' => $name];
                    }
                }
            }
        }

        return $usage;
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
