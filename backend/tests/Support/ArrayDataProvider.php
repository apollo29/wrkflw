<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\DataProviderInterface;

/**
 * Test-Double fuer den DataProvider: liefert Entitaeten aus einem In-Memory-Array.
 */
final class ArrayDataProvider implements DataProviderInterface
{
    /** @param array<string,list<array<string,mixed>>> $data */
    public function __construct(private readonly array $data = [])
    {
    }

    public function get(string $entity, string|int $id): ?array
    {
        foreach ($this->data[$entity] ?? [] as $row) {
            $rowId = $row['id'] ?? null;
            if (is_scalar($rowId) && (string) $rowId === (string) $id) {
                return $row;
            }
        }

        return null;
    }

    public function find(string $entity, array $criteria): array
    {
        $rows = $this->data[$entity] ?? [];
        if ($criteria === []) {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($criteria): bool {
                foreach ($criteria as $key => $expected) {
                    if (($row[$key] ?? null) !== $expected) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}
