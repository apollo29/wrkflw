<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Eine geplante Änderung, wie Regeln sie sehen: alle Werte nach dem Speichern, welche
 * Felder sich ändern (F-126), ob angelegt wird, und woher die Änderung kommt.
 */
final class Change
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $values  alle Werte nach dem Speichern
     * @param array<string, mixed> $changed nur die geänderten Felder
     */
    public function __construct(
        public readonly DataModel $model,
        public readonly string $id,
        public readonly bool $isCreate,
        private array $values,
        private array $changed,
        public readonly ChangeOrigin $origin,
    ) {
    }

    public function value(string $field): mixed
    {
        return $this->values[$field] ?? null;
    }

    public function isChanged(string $field): bool
    {
        return array_key_exists($field, $this->changed);
    }

    /**
     * @return list<string>
     */
    public function changedFields(): array
    {
        return array_keys($this->changed);
    }

    /** Ergänzt oder überschreibt einen Wert; das Feld gilt dann als geändert. */
    public function set(string $field, mixed $value): void
    {
        $this->values[$field] = $value;
        $this->changed[$field] = $value;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return $this->changed;
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
