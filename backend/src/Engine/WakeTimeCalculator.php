<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Exception\ExpressionException;
use WorkflowEngine\Instance\WorkflowInstance;

/** Berechnet, wann ein Warteschritt weiterläuft (F-60). */
final readonly class WakeTimeCalculator
{
    public function __construct(
        private ExpressionEvaluatorInterface $expressions,
        private ClockInterface $clock,
    ) {
    }

    public function wakeAt(Step $step, WorkflowInstance $instance): \DateTimeImmutable
    {
        $now = $this->clock->now();

        if ($step->delaySeconds !== null) {
            return $now->modify("+{$step->delaySeconds} seconds");
        }

        if ($step->untilExpr !== null) {
            $value = $this->expressions->evaluateValue($step->untilExpr, [
                'context' => $instance->context,
                'now' => $now->getTimestamp(),
            ]);
            if (!is_numeric($value)) {
                throw new ExpressionException("Der Zeitpunkt von Schritt '{$step->name}' ergab keinen Zeitstempel.");
            }

            return $now->setTimestamp((int) $value);
        }

        return $now;
    }
}
