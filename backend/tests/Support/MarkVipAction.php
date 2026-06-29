<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Beispielhafte Host-App-Action: leitet aus dem Kontext einen neuen Wert ab
 * (hier: VIP-Status anhand des Betrags) und gibt ihn zum Merge in den Kontext zurueck.
 *
 * Zeigt das uebliche Muster fuer eigene Aktionen der Host-App: lesen aus
 * $instance->context, rechnen, Ergebnis als array<string,mixed> zurueckgeben.
 */
final class MarkVipAction implements ActionInterface
{
    public function __construct(private readonly int $threshold = 1000)
    {
    }

    public function execute(WorkflowInstance $instance, Step $step): array
    {
        $amount = $instance->context['amount'] ?? 0;
        $numeric = is_numeric($amount) ? (float) $amount : 0.0;

        return ['vip' => $numeric > $this->threshold];
    }
}
