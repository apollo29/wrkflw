<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\HistoryEntry;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Ablage von Definitionen, Durchläufen und Verlauf.
 *
 * Phase 1 enthält nur, was die Engine selbst braucht. Speichern und Auflisten von
 * Definitionen, das Belegen fälliger Durchläufe und Zähler folgen mit der Ablage
 * (Phase 3).
 */
interface WorkflowRepositoryInterface
{
    /**
     * Ohne Version: die neueste aktive Version (F-12).
     *
     * @throws NotFoundException
     */
    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition;

    public function saveInstance(WorkflowInstance $instance): void;

    public function findInstance(string $id): ?WorkflowInstance;

    /**
     * @param array<string, mixed> $detail nur Namen und technische Angaben, keine Werte
     */
    public function logHistory(string $instanceId, HistoryKind $kind, ?string $step, array $detail = []): void;

    /**
     * @return list<HistoryEntry> älteste zuerst
     */
    public function findHistory(string $instanceId): array;
}
