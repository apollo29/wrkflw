<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Trigger\TriggerStart;

/**
 * Eine Regel, die Abläufe aufgrund von Daten selbst startet (F-61). Der Hintergrundlauf
 * fragt sie regelmässig ab. Trigger, die mehrere Tabellen verknüpfen, bleiben eigene
 * Klassen der Host-App (5.6); einfache Fälle deckt {@see \WorkflowEngine\Trigger\ModelSearchTrigger} ab.
 */
interface TriggerInterface
{
    /**
     * @return list<TriggerStart>
     */
    public function poll(): array;
}
