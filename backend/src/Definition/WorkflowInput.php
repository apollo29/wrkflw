<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Ein Wert, den ein Ablauf beim Start braucht (F-04). Im JSON heisst das Beispiel
 * `beispiel` (2.x-Format, F-114).
 */
final readonly class WorkflowInput
{
    public function __construct(
        public string $name,
        public string $label,
        public bool $required = false,
        public string $example = '',
    ) {
    }

    /**
     * Namenlose Angaben werden ignoriert. Ohne Angabe ist ein Wert NICHT Pflicht: der
     * umgekehrte Vorgabewert schaltete bestehende Definitionen ungefragt scharf.
     *
     * @param array<mixed> $d
     */
    public static function fromArray(array $d): ?self
    {
        $name = self::text($d['name'] ?? null);
        if ($name === '') {
            return null;
        }
        $label = self::text($d['label'] ?? null);

        return new self(
            name: $name,
            label: $label !== '' ? $label : $name,
            required: ($d['required'] ?? false) === true,
            example: self::text($d['beispiel'] ?? null),
        );
    }

    /**
     * Ein leerer Wert zählt als fehlend, `0` und `false` zählen als Wert (F-21).
     *
     * @param array<string, mixed> $context
     */
    public function isMissingIn(array $context): bool
    {
        if (!$this->required) {
            return false;
        }
        $value = $context[$this->name] ?? null;

        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @return array{name: string, label: string, required: bool, beispiel?: string}
     */
    public function toArray(): array
    {
        $out = ['name' => $this->name, 'label' => $this->label, 'required' => $this->required];
        if ($this->example !== '') {
            $out['beispiel'] = $this->example;
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
