<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Beschreibt ein Datenmodell im Code der Host-App (F-113):
 *
 *     DataModel::define('antrag', 'Anträge')
 *         ->text('status', 'Status')->writable()
 *         ->number('betrag', 'Betrag')
 *         ->subset(['archiviert' => 0])
 *         ->updatable()
 *         ->build();
 *
 * Ohne `id()` gilt ein Textfeld `id` als ID-Feld.
 */
final class DataModelBuilder
{
    /** @var list<Field> */
    private array $fields = [];

    private ?string $idField = null;

    private string $persistence = 'db';

    private ?string $source = null;

    /** @var array<string, string|int|float|bool|null> */
    private array $subset = [];

    private bool $canUpdate = false;

    private bool $canCreate = false;

    /** @var list<RuleInterface> */
    private array $rules = [];

    public function __construct(private readonly string $name, private readonly string $label)
    {
    }

    public function id(string $name, FieldType $type = FieldType::Text, string $label = 'ID', ?string $actual = null): self
    {
        $this->idField = $name;
        $this->fields[] = new Field($name, $label, $type, $actual);

        return $this;
    }

    public function field(Field $field): self
    {
        $this->fields[] = $field;

        return $this;
    }

    public function text(string $name, string $label, ?string $actual = null): self
    {
        return $this->field(Field::text($name, $label, $actual));
    }

    public function number(string $name, string $label, ?string $actual = null): self
    {
        return $this->field(Field::number($name, $label, $actual));
    }

    public function bool(string $name, string $label, ?string $actual = null): self
    {
        return $this->field(Field::bool($name, $label, $actual));
    }

    public function date(string $name, string $label, ?string $actual = null): self
    {
        return $this->field(Field::date($name, $label, $actual));
    }

    public function datetime(string $name, string $label, ?string $actual = null): self
    {
        return $this->field(Field::datetime($name, $label, $actual));
    }

    /** Gibt das zuletzt beschriebene Feld zum Schreiben frei (F-117). */
    public function writable(): self
    {
        $last = array_key_last($this->fields);
        if ($last === null) {
            throw DataModelException::invalid($this->name, 'writable() ohne vorheriges Feld.');
        }
        $this->fields[$last] = $this->fields[$last]->asWritable();

        return $this;
    }

    public function persistence(string $name): self
    {
        $this->persistence = $name;

        return $this;
    }

    public function source(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * @param array<string, string|int|float|bool|null> $conditions
     */
    public function subset(array $conditions): self
    {
        $this->subset = $conditions;

        return $this;
    }

    public function updatable(): self
    {
        $this->canUpdate = true;

        return $this;
    }

    public function creatable(): self
    {
        $this->canCreate = true;

        return $this;
    }

    public function rule(RuleInterface $rule): self
    {
        $this->rules[] = $rule;

        return $this;
    }

    public function build(): DataModel
    {
        $fields = $this->fields;
        if ($this->idField === null) {
            array_unshift($fields, Field::text('id', 'ID'));
        }

        return new DataModel(
            name: $this->name,
            label: $this->label,
            idField: $this->idField ?? 'id',
            fields: $fields,
            persistence: $this->persistence,
            source: $this->source,
            subset: $this->subset,
            canUpdate: $this->canUpdate,
            canCreate: $this->canCreate,
            rules: $this->rules,
        );
    }
}
