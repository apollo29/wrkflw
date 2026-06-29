<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * PORT: Persistenz von Definitionen und Instanzen.
 * Default-Implementierung: PdoWorkflowRepository (MariaDB).
 */
interface WorkflowRepositoryInterface
{
    /**
     * Laedt eine Definition. Ohne Versionsangabe die neueste aktive Version.
     *
     * @throws \WorkflowEngine\Exception\WorkflowException wenn nicht gefunden
     */
    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition;

    public function saveInstance(WorkflowInstance $instance): void;

    public function findInstance(string $id): ?WorkflowInstance;

    /**
     * Instanzen, die der Cron-Runner aufwecken soll
     * (status = waiting_timer und wake_at <= jetzt).
     *
     * @return list<WorkflowInstance>
     */
    public function findDueInstances(\DateTimeImmutable $now, int $limit = 50): array;

    /**
     * Schreibt einen Audit-/History-Eintrag.
     *
     * @param array<string,mixed> $detail
     */
    public function logHistory(
        string $instanceId,
        string $kind,
        ?string $step,
        array $detail = [],
    ): void;
}
