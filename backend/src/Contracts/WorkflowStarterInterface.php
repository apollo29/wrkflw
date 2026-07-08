<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Instance\WorkflowInstance;

/**
 * PORT: Erlaubt einer Action, einen weiteren Workflow zu starten (verknuepfte
 * Workflows). Die WorkflowEngine implementiert dieses Interface; eine Action haengt
 * nur von diesem Port ab, nicht von der Engine selbst (bricht den Zirkelbezug
 * Action -> Engine -> ActionRegistry -> Action).
 */
interface WorkflowStarterInterface
{
    /**
     * Reservierter Kontext-Schluessel: Rueckverweis eines Kind-Workflows auf seinen
     * wartenden Eltern-Workflow ({instanceId: <parent-id>}).
     */
    public const PARENT_LINK = '__parent';

    /**
     * Reservierter Kontext-Schluessel: der Eltern-Workflow wartet auf den Abschluss
     * dieser Kind-Instanz-ID (nur im "waitForCompletion"-Modus gesetzt).
     */
    public const AWAIT_WORKFLOW = '__awaitWorkflow';

    /** Reservierter Kontext-Schluessel: Verschachtelungstiefe (Guard gegen Endlos-Ketten). */
    public const SUB_DEPTH = '__subDepth';

    /**
     * Startet eine neue Instanz der genannten Definition und laesst sie bis zum
     * ersten Halt laufen (identisch zu einem regulaeren Start).
     *
     * @param array<string,mixed> $context Anfangs-Kontext des Kind-Workflows
     */
    public function startWorkflow(
        string $definitionId,
        array $context = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): WorkflowInstance;
}
