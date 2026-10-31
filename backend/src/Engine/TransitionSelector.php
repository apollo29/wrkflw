<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\Transition;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Wählt den ersten Übergang, dessen Bedingung zutrifft (F-03). Mit Ereignis zählen nur
 * Übergänge mit genau diesem Ereignis, ohne Ereignis nur solche ohne.
 */
final readonly class TransitionSelector
{
    public function __construct(
        private ExpressionEvaluatorInterface $expressions,
        private ClockInterface $clock,
    ) {
    }

    public function select(Step $step, WorkflowInstance $instance, ?string $event): ?Transition
    {
        $scope = ['context' => $instance->context, 'now' => $this->clock->now()->getTimestamp()];

        foreach ($step->transitions as $transition) {
            if ($transition->event !== $event) {
                continue;
            }
            if ($this->expressions->evaluate($transition->when, $scope)) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * Ein Schritt, den der Ablauf nicht verlassen KANN: er hat Übergänge, aber jeder
     * verlangt ein Ereignis. Keine Übergänge heisst dagegen «hier ist Schluss», und eine
     * Bedingung, die diesmal nicht zutrifft, ist eine nicht genommene Verzweigung.
     */
    public static function isDeadEnd(Step $step): bool
    {
        if ($step->transitions === []) {
            return false;
        }
        foreach ($step->transitions as $transition) {
            if ($transition->event === null) {
                return false;
            }
        }

        return true;
    }
}
