<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Contracts\ExpressionCheckerInterface;
use WorkflowEngine\Contracts\StepRuleInterface;
use WorkflowEngine\Exception\ExpressionException;
use WorkflowEngine\Exception\InvalidDefinitionException;

/**
 * Prüft den Graphen einer Definition fachlich (F-05) und sammelt ALLE Fehler (F-06).
 *
 * Geprüft wird: Start-Schritt und Übergangsziele existieren; jeder Schritt ist vom
 * Start aus erreichbar und kann ein Ende erreichen; interaktive Schritte haben einen
 * Ausgang mit Ereignis, alle anderen einen ohne; Wartezeiten und Ausdrücke sind
 * gültig; dazu die Regeln je Schritt der Host-App.
 */
final readonly class DefinitionValidator
{
    /**
     * @param iterable<StepRuleInterface> $rules
     */
    public function __construct(
        private ?ExpressionCheckerInterface $checker = null,
        private iterable $rules = [],
    ) {
    }

    /**
     * @throws InvalidDefinitionException mit allen Fehlern
     */
    public function validate(WorkflowDefinition $def): void
    {
        $report = $this->check($def);
        if (!$report->isValid()) {
            throw InvalidDefinitionException::fromReport($report);
        }
    }

    public function check(WorkflowDefinition $def): ValidationReport
    {
        $errors = [];

        if (!$def->hasStep($def->startStep)) {
            $errors[] = new ValidationError(
                ValidationCode::StartStepMissing,
                "Start-Schritt '{$def->startStep}' existiert nicht.",
            );
        }

        foreach ($def->steps as $name => $step) {
            array_push(
                $errors,
                ...$this->checkTargets($def, $step),
                ...$this->checkExits($step),
                ...$this->checkTimer($step),
                ...$this->checkExpressions($step),
            );
        }

        if ($def->hasStep($def->startStep)) {
            $reachable = $this->reachableFromStart($def);
            $canFinish = $this->stepsThatCanFinish($def);
            foreach (array_keys($def->steps) as $name) {
                if (!isset($reachable[$name])) {
                    $errors[] = new ValidationError(
                        ValidationCode::Unreachable,
                        "Schritt '{$name}' ist vom Start aus nicht erreichbar.",
                        $name,
                    );
                } elseif (!isset($canFinish[$name])) {
                    $errors[] = new ValidationError(
                        ValidationCode::NoExit,
                        "Schritt '{$name}' kann kein Ende erreichen (Schleife ohne Ausgang).",
                        $name,
                    );
                }
            }
        }

        foreach ($this->rules as $rule) {
            foreach ($def->steps as $step) {
                array_push($errors, ...$rule->check($step, $def));
            }
        }

        usort($errors, static fn (ValidationError $a, ValidationError $b): int => self::stepOrder($def, $a) <=> self::stepOrder($def, $b));

        return new ValidationReport($errors);
    }

    /**
     * @return list<ValidationError>
     */
    private function checkTargets(WorkflowDefinition $def, Step $step): array
    {
        $errors = [];
        foreach ($step->transitions as $i => $t) {
            if (!$def->hasStep($t->to)) {
                $errors[] = new ValidationError(
                    ValidationCode::TargetUnknown,
                    "Schritt '{$step->name}' verweist auf das unbekannte Ziel '{$t->to}'.",
                    $step->name,
                    $i,
                );
            }
        }

        return $errors;
    }

    /**
     * Ein interaktiver Schritt wartet auf einen Knopfdruck; ohne Übergang mit Ereignis
     * gibt es keinen Knopf. Jeder andere Schritt bekommt nie einen Knopfdruck und
     * braucht einen Übergang ohne Ereignis.
     *
     * @return list<ValidationError>
     */
    private function checkExits(Step $step): array
    {
        if ($step->isTerminal()) {
            return [];
        }

        $withEvent = array_filter($step->transitions, static fn (Transition $t): bool => $t->event !== null);

        if ($step->isInteractive() && $withEvent === []) {
            return [new ValidationError(
                ValidationCode::InteractiveWithoutEvent,
                "Schritt '{$step->name}' ist interaktiv, aber kein Übergang hat ein Ereignis. Ohne Ereignis entsteht kein Knopf.",
                $step->name,
            )];
        }

        if (!$step->isInteractive() && count($withEvent) === count($step->transitions)) {
            return [new ValidationError(
                ValidationCode::NoEventlessExit,
                "Schritt '{$step->name}' braucht einen Übergang ohne Ereignis: ein Schritt vom Typ '{$step->type->value}' bekommt nie einen Knopfdruck.",
                $step->name,
            )];
        }

        return [];
    }

    /**
     * @return list<ValidationError>
     */
    private function checkTimer(Step $step): array
    {
        if ($step->delaySeconds !== null && $step->delaySeconds < 0) {
            return [new ValidationError(
                ValidationCode::DelayInvalid,
                "Schritt '{$step->name}' hat eine negative Wartezeit.",
                $step->name,
            )];
        }

        return [];
    }

    /**
     * @return list<ValidationError>
     */
    private function checkExpressions(Step $step): array
    {
        if ($this->checker === null) {
            return [];
        }

        $errors = [];
        foreach ($step->transitions as $i => $t) {
            try {
                $this->checker->check($t->when);
            } catch (ExpressionException $e) {
                $errors[] = new ValidationError(
                    ValidationCode::ExpressionInvalid,
                    "Schritt '{$step->name}', Übergang nach '{$t->to}': {$e->getMessage()}",
                    $step->name,
                    $i,
                );
            }
        }

        if ($step->untilExpr !== null) {
            try {
                $this->checker->check($step->untilExpr);
            } catch (ExpressionException $e) {
                $errors[] = new ValidationError(
                    ValidationCode::ExpressionInvalid,
                    "Schritt '{$step->name}', Zeitpunkt: {$e->getMessage()}",
                    $step->name,
                );
            }
        }

        return $errors;
    }

    /**
     * @return array<string, true>
     */
    private function reachableFromStart(WorkflowDefinition $def): array
    {
        $reachable = [];
        $queue = [$def->startStep];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($reachable[$name])) {
                continue;
            }
            $reachable[$name] = true;
            foreach ($def->step($name)->transitions as $t) {
                if ($def->hasStep($t->to)) {
                    $queue[] = $t->to;
                }
            }
        }

        return $reachable;
    }

    /**
     * Rückwärtssuche von den Endschritten über die Vorgänger.
     *
     * @return array<string, true>
     */
    private function stepsThatCanFinish(WorkflowDefinition $def): array
    {
        $predecessors = [];
        $queue = [];
        foreach ($def->steps as $name => $step) {
            if ($step->isTerminal()) {
                $queue[] = $name;
            }
            foreach ($step->transitions as $t) {
                $predecessors[$t->to][] = $name;
            }
        }

        $canFinish = [];
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($canFinish[$name])) {
                continue;
            }
            $canFinish[$name] = true;
            foreach ($predecessors[$name] ?? [] as $pred) {
                $queue[] = $pred;
            }
        }

        return $canFinish;
    }

    /** Fehler ohne Schritt zuerst, danach in der Reihenfolge der Schritte; stabil. */
    private static function stepOrder(WorkflowDefinition $def, ValidationError $e): int
    {
        if ($e->step === null) {
            return -1;
        }
        $index = array_search($e->step, array_keys($def->steps), true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
