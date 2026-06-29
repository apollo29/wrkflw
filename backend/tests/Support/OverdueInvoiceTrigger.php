<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\DataProviderInterface;
use WorkflowEngine\Contracts\TriggerInterface;

/**
 * Beispielhafter datengetriebener Trigger: startet fuer jede ueberfaellige Rechnung
 * einen Workflow. Zeigt das Muster, wie eine Host-App ueber den DataProvider pollt.
 */
final class OverdueInvoiceTrigger implements TriggerInterface
{
    public function __construct(
        private readonly DataProviderInterface $data,
        private readonly int $threshold = 14,
        private readonly string $definition = 'dunning',
        private readonly string $entity = 'invoice',
    ) {
    }

    public function poll(): array
    {
        $jobs = [];
        foreach ($this->data->find($this->entity, []) as $row) {
            $daysOverdue = $row['daysOverdue'] ?? 0;
            if (!is_numeric($daysOverdue) || $daysOverdue <= $this->threshold) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (!is_scalar($id)) {
                continue;
            }
            $id = (string) $id;

            $jobs[] = [
                'definition' => $this->definition,
                'context' => ['invoiceId' => $id],
                'subjectType' => $this->entity,
                'subjectId' => $id,
            ];
        }

        return $jobs;
    }
}
