<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Eine einfache Suchbedingung auf einem Feld. Mehrere Bedingungen sind mit UND
 * verknüpft (F-122).
 */
final readonly class Condition
{
    public function __construct(
        public string $field,
        public ConditionOperator $operator,
        public string|int|float|bool|null $value = null,
    ) {
    }

    /** Auswertung im Arbeitsspeicher (für Persistenzen ohne eigene Abfragesprache). */
    public function matches(mixed $actual): bool
    {
        $isEmpty = $actual === null || $actual === '';

        return match ($this->operator) {
            ConditionOperator::Empty => $isEmpty,
            ConditionOperator::NotEmpty => !$isEmpty,
            ConditionOperator::Eq => !$isEmpty && $this->compare($actual) === 0,
            ConditionOperator::Ne => $isEmpty || $this->compare($actual) !== 0,
            ConditionOperator::Lt => !$isEmpty && $this->compare($actual) < 0,
            ConditionOperator::Le => !$isEmpty && $this->compare($actual) <= 0,
            ConditionOperator::Gt => !$isEmpty && $this->compare($actual) > 0,
            ConditionOperator::Ge => !$isEmpty && $this->compare($actual) >= 0,
        };
    }

    private function compare(mixed $actual): int
    {
        if (is_numeric($actual) && is_numeric($this->value)) {
            return (float) $actual <=> (float) $this->value;
        }
        if (is_bool($this->value)) {
            return (bool) $actual <=> $this->value;
        }

        return strcmp(is_scalar($actual) ? (string) $actual : '', (string) $this->value);
    }
}
