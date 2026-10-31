<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Sperrfrist für Trigger: derselbe Datensatz startet innerhalb der Frist höchstens einen
 * Durchlauf (F-143, offener Punkt 2).
 */
interface TriggerLockInterface
{
    /**
     * Belegt die Sperre, wenn sie frei oder abgelaufen ist.
     *
     * @return bool true, wenn der Aufrufer starten darf
     */
    public function acquire(string $triggerKey, string $subjectKey, \DateTimeImmutable $now, \DateTimeImmutable $until): bool;

    /** Gibt eine Sperre frei, etwa wenn der Start doch scheiterte. */
    public function release(string $triggerKey, string $subjectKey): void;
}
