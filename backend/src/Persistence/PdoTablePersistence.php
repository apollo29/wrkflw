<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Contracts\PersistenceInterface;
use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Model\Field;
use WorkflowEngine\Model\FieldType;

/**
 * Persistenz für eine Tabelle der Host-App in MariaDB/MySQL (F-129).
 *
 * - Tabelle = `DataModel::sourceName()`, Spalten = `Field::storageName()`.
 * - Bezeichner kommen aus Datenmodellen (Code der Host-App oder geprüfte Admin-Eingabe),
 *   werden trotzdem gegen ein einfaches Muster geprüft und quotiert. Werte laufen
 *   ausschliesslich über gebundene Parameter.
 * - Die Teilmenge des Modells gilt bei jedem Vorgang; beim Anlegen wird sie gesetzt.
 * - Ja/Nein wird 0/1, ein Zeitpunkt `Y-m-d H:i:s` in der eingestellten Zeitzone.
 */
final readonly class PdoTablePersistence implements PersistenceInterface
{
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private \DateTimeZone $timezone;

    public function __construct(private \PDO $pdo, ?\DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new \DateTimeZone(date_default_timezone_get());
    }

    public function load(DataModel $model, string $id): ?array
    {
        [$where, $params] = $this->scope($model, $id);
        $statement = $this->pdo->prepare(sprintf(
            'SELECT %s FROM %s WHERE %s LIMIT 1',
            $this->selectList($model),
            $this->quote($model->sourceName()),
            $where,
        ));
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $this->fromStorage($model, $row) : null;
    }

    public function find(DataModel $model, array $conditions, int $limit): array
    {
        $clauses = [];
        $params = [];
        foreach ($model->subset as $column => $value) {
            $clauses[] = $this->quote($column) . ' = ?';
            $params[] = $this->scalar($value);
        }
        foreach ($conditions as $condition) {
            [$clause, $conditionParams] = $this->condition($model->field($condition->field), $condition);
            $clauses[] = $clause;
            array_push($params, ...$conditionParams);
        }

        $statement = $this->pdo->prepare(sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d',
            $this->selectList($model),
            $this->quote($model->sourceName()),
            $clauses === [] ? '1 = 1' : implode(' AND ', $clauses),
            $this->quote($model->idFieldDefinition()->storageName()),
            max(1, $limit),
        ));
        $statement->execute($params);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $rows[] = $this->fromStorage($model, $row);
            }
        }

        return $rows;
    }

    public function update(DataModel $model, string $id, array $values, ChangeOrigin $origin): bool
    {
        [$where, $params] = $this->scope($model, $id);

        $this->pdo->beginTransaction();
        try {
            $check = $this->pdo->prepare(sprintf(
                'SELECT 1 FROM %s WHERE %s LIMIT 1 FOR UPDATE',
                $this->quote($model->sourceName()),
                $where,
            ));
            $check->execute($params);
            if ($check->fetchColumn() === false) {
                $this->pdo->rollBack();

                return false;
            }

            if ($values !== []) {
                $sets = [];
                $setParams = [];
                foreach ($values as $name => $value) {
                    $field = $model->field($name);
                    $sets[] = $this->quote($field->storageName()) . ' = ?';
                    $setParams[] = $this->toStorage($field, $value);
                }
                $statement = $this->pdo->prepare(sprintf(
                    'UPDATE %s SET %s WHERE %s',
                    $this->quote($model->sourceName()),
                    implode(', ', $sets),
                    $where,
                ));
                $statement->execute([...$setParams, ...$params]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return true;
    }

    public function create(DataModel $model, ?string $id, array $values, ChangeOrigin $origin): string
    {
        $columns = [];
        $params = [];
        if ($id !== null) {
            $columns[] = $this->quote($model->idFieldDefinition()->storageName());
            $params[] = $id;
        }
        foreach ($values as $name => $value) {
            $field = $model->field($name);
            $columns[] = $this->quote($field->storageName());
            $params[] = $this->toStorage($field, $value);
        }
        foreach ($model->subset as $column => $value) {
            $columns[] = $this->quote($column);
            $params[] = $this->scalar($value);
        }

        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quote($model->sourceName()),
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ));
        $statement->execute($params);

        return $id ?? (string) $this->pdo->lastInsertId();
    }

    /**
     * WHERE für genau einen Datensatz in der Teilmenge.
     *
     * @return array{string, list<mixed>}
     */
    private function scope(DataModel $model, string $id): array
    {
        $clauses = [$this->quote($model->idFieldDefinition()->storageName()) . ' = ?'];
        $params = [$id];
        foreach ($model->subset as $column => $value) {
            $clauses[] = $this->quote($column) . ' = ?';
            $params[] = $this->scalar($value);
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @return array{string, list<mixed>}
     */
    private function condition(Field $field, Condition $condition): array
    {
        $column = $this->quote($field->storageName());

        return match ($condition->operator) {
            ConditionOperator::Empty => ["({$column} IS NULL OR {$column} = '')", []],
            ConditionOperator::NotEmpty => ["({$column} IS NOT NULL AND {$column} <> '')", []],
            ConditionOperator::Ne => ["({$column} IS NULL OR {$column} <> ?)", [$this->toStorage($field, $field->normalize($condition->value))]],
            default => ["{$column} {$condition->operator->value} ?", [$this->toStorage($field, $field->normalize($condition->value))]],
        };
    }

    private function selectList(DataModel $model): string
    {
        return implode(', ', array_map(
            fn (Field $f): string => $this->quote($f->storageName()) . ' AS ' . $this->quote($f->name),
            $model->fields,
        ));
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function fromStorage(DataModel $model, array $row): array
    {
        $values = [];
        foreach ($model->fields as $name => $field) {
            $value = $row[$name] ?? null;
            if ($field->type === FieldType::DateTime && is_string($value) && $value !== '') {
                $value = (new \DateTimeImmutable($value, $this->timezone))->format(\DATE_ATOM);
            }
            $values[$name] = $value;
        }

        return $values;
    }

    private function toStorage(Field $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($field->type) {
            FieldType::Bool => $value ? 1 : 0,
            FieldType::DateTime => is_string($value)
                ? (new \DateTimeImmutable($value))->setTimezone($this->timezone)->format('Y-m-d H:i:s')
                : $value,
            default => $value,
        };
    }

    private function scalar(string|int|float|bool|null $value): string|int|float|null
    {
        return is_bool($value) ? (int) $value : $value;
    }

    private function quote(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new DataModelException("Ungültiger Bezeichner '{$identifier}'.");
        }

        return '`' . $identifier . '`';
    }
}
