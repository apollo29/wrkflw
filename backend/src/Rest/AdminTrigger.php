<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;

/**
 * Ein Trigger, den ein Admin ohne Code einrichtet (F-142, 9.16): Suche über ein
 * Datenmodell, zu startender Ablauf, Zuordnung der Felder zum Startkontext, Takt und
 * Sperrfrist. Ein neuer Trigger ist aus.
 */
final readonly class AdminTrigger
{
    public const int MIN_INTERVAL_MINUTES = 5;

    /**
     * @param list<Condition>       $conditions mit UND verknüpft
     * @param array<string, string> $inputs     Angabe des Ablaufs => Feld des Modells
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $model,
        public array $conditions,
        public string $definitionId,
        public array $inputs,
        public int $intervalMinutes = 15,
        public int $lockDays = 7,
        public bool $active = false,
        public ?\DateTimeImmutable $lastPolledAt = null,
    ) {
    }

    public function isDue(\DateTimeImmutable $now): bool
    {
        return $this->active
            && ($this->lastPolledAt === null || $this->lastPolledAt->modify("+{$this->intervalMinutes} minutes") <= $now);
    }

    public function key(): string
    {
        return 'admin:' . $this->id;
    }

    /**
     * @return list<array{field: string, operator: string, value: string|int|float|bool|null}>
     */
    public function conditionsToArray(): array
    {
        return array_map(
            static fn (Condition $c): array => ['field' => $c->field, 'operator' => $c->operator->value, 'value' => $c->value],
            $this->conditions,
        );
    }

    /**
     * @param array<mixed> $raw
     *
     * @return list<Condition>
     */
    public static function conditionsFromArray(array $raw): array
    {
        $conditions = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !is_string($entry['field'] ?? null)) {
                throw new RestSpecException('Eine Bedingung hat kein Feld.');
            }
            $operator = ConditionOperator::tryFrom(is_string($entry['operator'] ?? null) ? $entry['operator'] : '=')
                ?? throw new RestSpecException("Bedingung auf '{$entry['field']}' hat einen unbekannten Vergleich.");
            $value = $entry['value'] ?? null;
            $conditions[] = new Condition($entry['field'], $operator, is_scalar($value) ? $value : null);
        }

        return $conditions;
    }

    /**
     * @return array{id: string, name: string, model: string, conditions: list<array{field: string, operator: string, value: string|int|float|bool|null}>, definitionId: string, inputs: array<string, string>, intervalMinutes: int, lockDays: int, active: bool, lastPolledAt: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'model' => $this->model,
            'conditions' => $this->conditionsToArray(),
            'definitionId' => $this->definitionId,
            'inputs' => $this->inputs,
            'intervalMinutes' => $this->intervalMinutes,
            'lockDays' => $this->lockDays,
            'active' => $this->active,
            'lastPolledAt' => $this->lastPolledAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Aus einer Eingabe der Oberfläche; `lastPolledAt` setzt nur die Ablage.
     *
     * @param array<mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $text = static fn (string $key): string => is_string($d[$key] ?? null) ? trim($d[$key]) : '';
        $int = static function (string $key, int $default) use ($d): int {
            $value = $d[$key] ?? null;
            if ($value === null) {
                return $default;
            }

            return is_int($value) ? $value : throw new RestSpecException("'{$key}' muss eine ganze Zahl sein.");
        };
        $inputs = [];
        foreach (is_array($d['inputs'] ?? null) ? $d['inputs'] : [] as $input => $field) {
            if (!is_string($field) || $field === '') {
                throw new RestSpecException("Die Angabe '{$input}' braucht ein Feld.");
            }
            $inputs[(string) $input] = $field;
        }

        return new self(
            id: $text('id'),
            name: $text('name'),
            model: $text('model'),
            conditions: self::conditionsFromArray(is_array($d['conditions'] ?? null) ? $d['conditions'] : []),
            definitionId: $text('definitionId'),
            inputs: $inputs,
            intervalMinutes: $int('intervalMinutes', 15),
            lockDays: $int('lockDays', 7),
            active: ($d['active'] ?? false) === true,
        );
    }
}
