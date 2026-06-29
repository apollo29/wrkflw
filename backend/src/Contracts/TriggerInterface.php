<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Ein datengetriebener Start-Trigger, den der Cron-Runner periodisch prueft.
 * Beispiel: "Starte 'dunning', wenn eine Rechnung > 14 Tage ueberfaellig ist."
 *
 * Die Host-App implementiert solche Trigger und registriert sie im Runner.
 */
interface TriggerInterface
{
    /**
     * Liefert eine Liste zu startender Workflows.
     *
     * @return list<array{
     *     definition:string,
     *     context?:array<string,mixed>,
     *     subjectType?:string,
     *     subjectId?:string
     * }>
     */
    public function poll(): array;
}
