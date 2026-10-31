<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Beschreibt eine Datenart an einer Stelle (F-110): Name, Bezeichnung, ID-Feld, Felder,
 * Freigaben, Teilmenge, Regeln und Persistenz. Katalog, Lesen und Schreiben leiten sich
 * daraus ab.
 *
 * Unveränderlich: ein geladener Datensatz ist ein eigener {@see Record}, damit sich
 * Durchläufe im selben Hintergrundlauf nicht beeinflussen (5.6).
 */
final readonly class DataModel
{
    private const string NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]*$/';

    private const string FIELD_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** @var array<string, Field> */
    public array $fields;

    /**
     * @param list<Field>                           $fields
     * @param array<string, string|int|float|bool|null> $subset feste Bedingungen in Ablage-Namen (F-112)
     * @param list<RuleInterface>                   $rules
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $idField,
        array $fields,
        public string $persistence = 'db',
        public ?string $source = null,
        public array $subset = [],
        public bool $canUpdate = false,
        public bool $canCreate = false,
        public array $rules = [],
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw DataModelException::invalid($name, 'der Name darf nur Buchstaben, Ziffern, _ und - enthalten.');
        }

        $byName = [];
        foreach ($fields as $field) {
            if (preg_match(self::FIELD_PATTERN, $field->name) !== 1 || preg_match(self::FIELD_PATTERN, $field->storageName()) !== 1) {
                throw DataModelException::invalid($name, "Feldname '{$field->name}' ist kein einfacher Name.");
            }
            if (isset($byName[$field->name])) {
                throw DataModelException::invalid($name, "Feld '{$field->name}' ist doppelt beschrieben.");
            }
            if ($field->writable && !$canUpdate && !$canCreate) {
                throw DataModelException::invalid($name, "Feld '{$field->name}' ist schreibbar, aber das Modell erlaubt weder Ändern noch Anlegen.");
            }
            $byName[$field->name] = $field;
        }
        if (!isset($byName[$idField])) {
            throw DataModelException::invalid($name, "das ID-Feld '{$idField}' ist nicht beschrieben.");
        }
        foreach (array_keys($subset) as $column) {
            if (preg_match(self::FIELD_PATTERN, $column) !== 1) {
                throw DataModelException::invalid($name, "die Teilmenge nennt den ungültigen Namen '{$column}'.");
            }
        }

        $this->fields = $byName;
    }

    public static function define(string $name, string $label): DataModelBuilder
    {
        return new DataModelBuilder($name, $label);
    }

    public function field(string $name): Field
    {
        return $this->fields[$name] ?? throw DataModelException::unknownField($this->name, $name);
    }

    public function hasField(string $name): bool
    {
        return isset($this->fields[$name]);
    }

    public function idFieldDefinition(): Field
    {
        return $this->field($this->idField);
    }

    /**
     * @return array<string, Field>
     */
    public function writableFields(): array
    {
        return array_filter($this->fields, static fn (Field $f): bool => $f->writable);
    }

    /** Tabelle oder Pfad in der Persistenz; ohne Angabe der Modellname (F-114). */
    public function sourceName(): string
    {
        return $this->source ?? $this->name;
    }
}
