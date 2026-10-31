<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

use WorkflowEngine\Persistence\PersistenceRegistry;

/**
 * Die einzige Stelle, über die die Engine Daten der Host-App liest und schreibt (F-120):
 * laden, suchen, ändern, anlegen. Hier gelten Freigaben (F-117, F-121), Typen (F-119),
 * Regeln (F-125) und «nur Geändertes schreiben» (F-123). Wie und wo die Daten liegen,
 * weiss allein die Persistenz.
 */
final readonly class DataGateway
{
    /** Obergrenze für Suchergebnisse, damit eine Bedingung nie alles holt (F-122). */
    public const int MAX_RESULTS = 500;

    public function __construct(
        private DataModelRegistry $models,
        private PersistenceRegistry $persistences,
    ) {
    }

    public function model(string $name): DataModel
    {
        return $this->models->get($name);
    }

    /** Ein Datensatz, oder null wenn es ihn (in der Teilmenge) nicht gibt (F-124). */
    public function load(string $modelName, string $id): ?Record
    {
        $model = $this->models->get($modelName);
        $row = $this->persistences->for($model)->load($model, $id);

        return $row === null ? null : Record::loaded($model->name, $id, $this->readValues($model, $row));
    }

    /**
     * @param list<Condition> $conditions mit UND verknüpft
     *
     * @return list<Record>
     */
    public function find(string $modelName, array $conditions, int $limit = 100): array
    {
        $model = $this->models->get($modelName);
        foreach ($conditions as $condition) {
            $model->field($condition->field);
        }

        $records = [];
        $rows = $this->persistences->for($model)->find($model, $conditions, max(1, min($limit, self::MAX_RESULTS)));
        foreach ($rows as $row) {
            $id = $row[$model->idField] ?? null;
            if (is_scalar($id)) {
                $records[] = Record::loaded($model->name, (string) $id, $this->readValues($model, $row));
            }
        }

        return $records;
    }

    /**
     * Speichert die Änderungen eines Datensatzes: ändert ihn, oder legt ihn an, wenn er
     * neu ist. Ohne Änderung wird nichts geschrieben.
     *
     * @throws DataModelException          Freigabe fehlt, Feld unbekannt
     * @throws InvalidFieldValueException  Wert passt nicht zum Typ
     * @throws DataValidationException     eine Regel lehnt ab
     */
    public function save(Record $record, ChangeOrigin $origin): SaveResult
    {
        $model = $this->models->get($record->model);
        if ($record->isNew && !$model->canCreate) {
            throw DataModelException::operationNotAllowed($model->name, 'Anlegen');
        }
        if (!$record->isNew && !$model->canUpdate) {
            throw DataModelException::operationNotAllowed($model->name, 'Ändern');
        }

        $values = [];
        foreach ($record->values as $name => $value) {
            $values[$name] = $model->field($name)->normalize($value);
        }

        $changed = [];
        foreach ($values as $name => $value) {
            if ($record->isNew || !array_key_exists($name, $record->original) || $record->original[$name] !== $value) {
                $changed[$name] = $value;
            }
        }
        unset($changed[$model->idField]);

        foreach (array_keys($changed) as $name) {
            if (!$model->field($name)->writable) {
                throw DataModelException::notWritable($model->name, $name);
            }
        }

        if ($changed === [] && !$record->isNew) {
            return new SaveResult(false, $record->id);
        }

        $change = new Change($model, $record->id, $record->isNew, $values, $changed, $origin);
        foreach ($model->rules as $rule) {
            $rule->beforeSave($change);
        }
        if ($change->errors() !== []) {
            throw new DataValidationException($model->name, $change->errors());
        }

        $final = [];
        foreach ($change->changes() as $name => $value) {
            $final[$name] = $model->field($name)->normalize($value);
        }
        unset($final[$model->idField]);

        $persistence = $this->persistences->for($model);
        if ($record->isNew) {
            $id = $persistence->create($model, $record->id === '' ? null : $record->id, $final, $origin);

            return new SaveResult(true, $id, array_keys($final), created: true);
        }

        if (!$persistence->update($model, $record->id, $final, $origin)) {
            return new SaveResult(false, $record->id);
        }

        return new SaveResult(true, $record->id, array_keys($final));
    }

    /**
     * Werte aus der Persistenz in Normalform. Ein Wert, der nicht zum Typ passt, bleibt
     * unverändert: lesen soll an fremden Daten nicht scheitern.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function readValues(DataModel $model, array $row): array
    {
        $values = [];
        foreach ($model->fields as $name => $field) {
            if (!array_key_exists($name, $row)) {
                continue;
            }
            try {
                $values[$name] = $name === $model->idField && is_scalar($row[$name])
                    ? (string) $row[$name]
                    : $field->normalize($row[$name]);
            } catch (InvalidFieldValueException) {
                $values[$name] = $row[$name];
            }
        }

        return $values;
    }
}
