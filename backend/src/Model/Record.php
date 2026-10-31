<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Ein Datensatz eines Datenmodells. Unveränderlich; `with()` liefert einen neuen
 * Datensatz und merkt sich, was sich geändert hat (F-123, F-126).
 */
final readonly class Record
{
    /**
     * @param array<string, mixed> $values   aktuelle Werte nach Feldnamen
     * @param array<string, mixed> $original Werte beim Laden
     */
    private function __construct(
        public string $model,
        public string $id,
        public array $values,
        public array $original,
        public bool $isNew,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function loaded(string $model, string $id, array $values): self
    {
        return new self($model, $id, $values, $values, false);
    }

    /** Ein neuer Datensatz, der beim Speichern angelegt wird. */
    public static function create(string $model, string $id = ''): self
    {
        return new self($model, $id, [], [], true);
    }

    public function get(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    public function with(string $field, mixed $value): self
    {
        return new self($this->model, $this->id, array_replace($this->values, [$field => $value]), $this->original, $this->isNew);
    }

    /**
     * Felder, deren Wert sich gegenüber dem Laden geändert hat (strikter Vergleich;
     * die Normalisierung übernimmt der Gateway vorher).
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = [];
        foreach ($this->values as $field => $value) {
            if (!array_key_exists($field, $this->original) || $this->original[$field] !== $value) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }
}
