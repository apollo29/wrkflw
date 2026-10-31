<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\StepRuleInterface;

/** Ein Schritt darf nur Aktionen nennen, die registriert sind. */
final readonly class KnownActionRule implements StepRuleInterface
{
    public function __construct(private ActionRegistry $actions)
    {
    }

    public function check(Step $step, WorkflowDefinition $definition): array
    {
        if ($step->action === null || $this->actions->has($step->action)) {
            return [];
        }

        return [new ValidationError(
            ValidationCode::RuleViolation,
            "Schritt '{$step->name}': die Aktion '{$step->action}' ist nicht verfügbar.",
            $step->name,
        )];
    }
}
