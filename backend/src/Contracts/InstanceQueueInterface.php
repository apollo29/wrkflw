<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Belegt fällige Durchläufe für den Hintergrundlauf (ADR 2, ADR 4).
 *
 * Fällig sind wartende Durchläufe, deren Weckzeit erreicht ist, und laufende, deren
 * Belegung älter als die Lease ist (abgestürzter Lauf, Q-03). Parallele Läufe erhalten
 * nie denselben Durchlauf (Q-02).
 */
interface InstanceQueueInterface
{
    /**
     * @return list<WorkflowInstance> belegt und auf «läuft» gesetzt
     */
    public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array;
}
