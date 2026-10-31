<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Die Sicht auf die Datenmodelle für Editor und API, abgeleitet aus den Modellen selbst
 * (F-110, F-82). Eine zweite Liste gibt es nicht.
 *
 * @phpstan-type FieldInfo array{name: string, label: string, type: string, writable: bool}
 * @phpstan-type ModelInfo array{name: string, label: string, idField: string, fields: list<FieldInfo>, operations: array{load: bool, find: bool, update: bool, create: bool}}
 */
final readonly class DataCatalog
{
    public function __construct(private DataModelRegistry $models)
    {
    }

    /**
     * Alle Modelle mit allen beschriebenen Feldern (jedes beschriebene Feld ist lesbar, F-117).
     *
     * @return list<ModelInfo>
     */
    public function readable(): array
    {
        return array_values(array_map(fn (DataModel $m): array => $this->describe($m, onlyWritable: false), $this->models->all()));
    }

    /**
     * Modelle, die Ändern oder Anlegen erlauben, mit ihren schreibbaren Feldern.
     *
     * @return list<ModelInfo>
     */
    public function writable(): array
    {
        $out = [];
        foreach ($this->models->all() as $model) {
            if ($model->canUpdate || $model->canCreate) {
                $out[] = $this->describe($model, onlyWritable: true);
            }
        }

        return $out;
    }

    /**
     * @return ModelInfo
     */
    private function describe(DataModel $model, bool $onlyWritable): array
    {
        $fields = [];
        foreach ($model->fields as $field) {
            if ($onlyWritable && !$field->writable) {
                continue;
            }
            $fields[] = [
                'name' => $field->name,
                'label' => $field->label,
                'type' => $field->type->value,
                'writable' => $field->writable,
            ];
        }

        return [
            'name' => $model->name,
            'label' => $model->label,
            'idField' => $model->idField,
            'fields' => $fields,
            'operations' => ['load' => true, 'find' => true, 'update' => $model->canUpdate, 'create' => $model->canCreate],
        ];
    }
}
