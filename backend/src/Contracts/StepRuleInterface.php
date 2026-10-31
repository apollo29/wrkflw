<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\ValidationError;
use WorkflowEngine\Definition\WorkflowDefinition;

/**
 * Zusätzliche Prüfung je Schritt beim Speichern einer Definition, etwa ob eine Aktion
 * existiert oder ob ein Datenmodell und seine Felder freigegeben sind (F-118).
 */
interface StepRuleInterface
{
    /**
     * @return list<ValidationError>
     */
    public function check(Step $step, WorkflowDefinition $definition): array;
}
