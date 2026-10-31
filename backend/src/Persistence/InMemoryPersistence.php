<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Contracts\PersistenceInterface;
use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\DataModel;

/**
 * Persistenz im Arbeitsspeicher (F-129): für Tests, Prototypen und die Demo. Datensätze
 * liegen in Ablage-Namen, damit Zuordnung (`actual`) und Teilmenge genauso wirken wie bei
 * einer Datenbank.
 */
final class InMemoryPersistence implements PersistenceInterface
{
    /** @var array<string, list<array<string, mixed>>> Quelle => Zeilen in Ablage-Namen */
    private array $rows = [];

    /** @var array<string, mixed>|null die zuletzt geschriebenen Werte nach Feldnamen */
    public ?array $lastWrite = null;

    public ?ChangeOrigin $lastOrigin = null;

    /**
     * @param list<array<string, mixed>> $rows Zeilen in Ablage-Namen
     */
    public function seed(string $source, array $rows, string $idColumn = 'id'): void
    {
        foreach ($rows as $row) {
            $id = $row[$idColumn] ?? null;
            $this->rows[$source][] = [$idColumn => is_scalar($id) ? (string) $id : ''] + $row;
        }
    }

    /**
     * Eine Zeile in Ablage-Namen, unabhängig von Teilmenge und Modell (für Tests).
     *
     * @return array<string, mixed>|null
     */
    public function row(string $source, string $id, string $idColumn = 'id'): ?array
    {
        foreach ($this->rows[$source] ?? [] as $row) {
            if (($row[$idColumn] ?? null) === $id) {
                return $row;
            }
        }

        return null;
    }

    public function load(DataModel $model, string $id): ?array
    {
        $index = $this->indexOf($model, $id);

        return $index === null ? null : $this->toFields($model, $this->rows[$model->sourceName()][$index]);
    }

    public function find(DataModel $model, array $conditions, int $limit): array
    {
        $found = [];
        foreach ($this->rows[$model->sourceName()] ?? [] as $row) {
            if (!$this->inSubset($model, $row)) {
                continue;
            }
            $values = $this->toFields($model, $row);
            foreach ($conditions as $condition) {
                if (!$condition->matches($values[$condition->field] ?? null)) {
                    continue 2;
                }
            }
            $found[] = $values;
            if (count($found) >= $limit) {
                break;
            }
        }

        return $found;
    }

    public function update(DataModel $model, string $id, array $values, ChangeOrigin $origin): bool
    {
        $index = $this->indexOf($model, $id);
        if ($index === null) {
            return false;
        }
        foreach ($values as $field => $value) {
            $this->rows[$model->sourceName()][$index][$model->field($field)->storageName()] = $value;
        }
        $this->lastWrite = $values;
        $this->lastOrigin = $origin;

        return true;
    }

    public function create(DataModel $model, ?string $id, array $values, ChangeOrigin $origin): string
    {
        $source = $model->sourceName();
        $id ??= (string) (count($this->rows[$source] ?? []) + 1);

        $row = [$model->idFieldDefinition()->storageName() => $id];
        foreach ($values as $field => $value) {
            $row[$model->field($field)->storageName()] = $value;
        }
        $this->rows[$source][] = $row + $model->subset;
        $this->lastWrite = $values;
        $this->lastOrigin = $origin;

        return $id;
    }

    private function indexOf(DataModel $model, string $id): ?int
    {
        $idColumn = $model->idFieldDefinition()->storageName();
        foreach ($this->rows[$model->sourceName()] ?? [] as $index => $row) {
            if (($row[$idColumn] ?? null) === $id && $this->inSubset($model, $row)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function inSubset(DataModel $model, array $row): bool
    {
        foreach ($model->subset as $column => $value) {
            if (($row[$column] ?? null) != $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function toFields(DataModel $model, array $row): array
    {
        $values = [];
        foreach ($model->fields as $name => $field) {
            if (array_key_exists($field->storageName(), $row)) {
                $values[$name] = $row[$field->storageName()];
            }
        }

        return $values;
    }
}
