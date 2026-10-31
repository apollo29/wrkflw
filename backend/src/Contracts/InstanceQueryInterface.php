<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Instance\InstanceFilter;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\InstanceSummary;

/** Abfragen über Durchläufe für den Betrieb (Reiter «Instanzen»). */
interface InstanceQueryInterface
{
    /**
     * Neueste zuerst.
     *
     * @return list<InstanceSummary>
     */
    public function listInstances(InstanceFilter $filter): array;

    /**
     * @return array<value-of<InstanceStatus>, int> Anzahl je Status, jeder Status vorhanden
     */
    public function countByStatus(?string $definitionId = null): array;
}
