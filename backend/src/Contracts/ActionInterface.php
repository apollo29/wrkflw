<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * PORT: Eine ausfuehrbare Aktion eines automatischen Schritts.
 * Eigene Aktionen werden in der ActionRegistry unter einem Schluessel registriert
 * und im Step ueber "action": "<key>" referenziert.
 */
interface ActionInterface
{
    /**
     * Fuehrt die Aktion aus. Darf den Kontext der Instanz veraendern
     * (z. B. Ergebnisse zurueckschreiben), die dann in Transitionen nutzbar sind.
     *
     * @return array<string,mixed> Werte, die in den Kontext gemerged werden
     */
    public function execute(WorkflowInstance $instance, Step $step): array;
}
