<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Ein Feld eines Datenmodells (F-115 bis F-119). Felder sind schreibgeschützt, bis die
 * Host-App oder ein Admin sie ausdrücklich freigibt.
 */
final readonly class Field
{
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type = FieldType::Text,
        public ?string $actual = null,
        public bool $writable = false,
    ) {
    }

    public static function text(string $name, string $label, ?string $actual = null): self
    {
        return new self($name, $label, FieldType::Text, $actual);
    }

    public static function number(string $name, string $label, ?string $actual = null): self
    {
        return new self($name, $label, FieldType::Number, $actual);
    }

    public static function bool(string $name, string $label, ?string $actual = null): self
    {
        return new self($name, $label, FieldType::Bool, $actual);
    }

    public static function date(string $name, string $label, ?string $actual = null): self
    {
        return new self($name, $label, FieldType::Date, $actual);
    }

    public static function datetime(string $name, string $label, ?string $actual = null): self
    {
        return new self($name, $label, FieldType::DateTime, $actual);
    }

    public function asWritable(): self
    {
        return new self($this->name, $this->label, $this->type, $this->actual, true);
    }

    /** Der Name in der Persistenz (Spalte, JSON-Pfad); ohne Zuordnung der Feldname. */
    public function storageName(): string
    {
        return $this->actual ?? $this->name;
    }

    /**
     * Prüft einen Wert gegen den Typ und bringt ihn in die Normalform: Text als string,
     * Zahl als int/float, Ja/Nein als bool, Datum als `Y-m-d`, Zeitpunkt als ISO 8601.
     * `null` heisst «leer». Ein leerer Text bleibt bei Textfeldern leer, sonst wird er null.
     *
     * @throws InvalidFieldValueException
     */
    public function normalize(mixed $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }
        if ($value === '' && $this->type !== FieldType::Text) {
            return null;
        }

        return match ($this->type) {
            FieldType::Text => $this->toText($value),
            FieldType::Number => $this->toNumber($value),
            FieldType::Bool => $this->toBool($value),
            FieldType::Date => $this->toDateTime($value)->format('Y-m-d'),
            FieldType::DateTime => $this->toDateTime($value)->format(\DATE_ATOM),
        };
    }

    private function toText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw $this->invalid('ist kein Text');
    }

    private function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            $trimmed = trim($value);

            if (preg_match('/^-?\d+$/', $trimmed) === 1) {
                return (int) $trimmed;
            }
            $number = (float) $trimmed;

            // Dezimalspalten liefern «1250.00»; ohne Nachkommastellen ist das eine Ganzzahl.
            return floor($number) === $number && abs($number) < 1e15 ? (int) $number : $number;
        }

        throw $this->invalid('ist keine Zahl');
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'ja', 'yes', 'wahr'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'nein', 'no', 'falsch'], true)) {
                return false;
            }
        }

        throw $this->invalid('ist kein Ja/Nein-Wert');
    }

    private function toDateTime(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value)) {
            return (new \DateTimeImmutable())->setTimestamp($value);
        }
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
                $value = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value, $m) === 1
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                try {
                    return new \DateTimeImmutable($value);
                } catch (\Exception) {
                    // fällt durch auf die Fehlermeldung
                }
            }
        }

        throw $this->invalid($this->type === FieldType::Date ? 'ist kein gültiges Datum' : 'ist kein gültiger Zeitpunkt');
    }

    private function invalid(string $reason): InvalidFieldValueException
    {
        return new InvalidFieldValueException($this->name, "Der Wert für «{$this->label}» ({$this->name}) {$reason}.");
    }
}
