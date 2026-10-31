<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Was ein automatischer Schritt tut (Mail senden, Daten lesen …). Die Host-App kann
 * eigene Aktionen ergänzen (F-40, Q-08).
 *
 * Eine Exception gilt als technischer Fehler: die Engine wiederholt den Schritt mit
 * wachsendem Abstand und markiert den Durchlauf danach als fehlgeschlagen (Q-01).
 */
interface ActionInterface
{
    public function execute(WorkflowInstance $instance, Step $step): ActionResult;
}
