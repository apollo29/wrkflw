<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\DataCatalogInterface;
use WorkflowEngine\Contracts\DataProviderInterface;

/**
 * In-Memory-Fake des DataProviders (+ Katalog) fuer Unit-Tests.
 */
final class InMemoryDataProvider implements DataProviderInterface, DataCatalogInterface
{
    /** @var array<string,array<string,array<string,mixed>>> entity => id => row */
    private array $rows = [];

    /** @var list<array{entity:string,label:string,fields:list<string>}> */
    private array $catalog = [];

    /**
     * @param array<string,mixed> $row
     */
    public function set(string $entity, string $id, array $row): void
    {
        $this->rows[$entity][$id] = $row;
    }

    /**
     * @param list<array{entity:string,label:string,fields:list<string>}> $catalog
     */
    public function setCatalog(array $catalog): void
    {
        $this->catalog = $catalog;
    }

    public function get(string $entity, string|int $id): ?array
    {
        return $this->rows[$entity][(string) $id] ?? null;
    }

    public function find(string $entity, array $criteria): array
    {
        $out = [];
        foreach ($this->rows[$entity] ?? [] as $row) {
            foreach ($criteria as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    continue 2;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    public function entities(): array
    {
        return $this->catalog;
    }
}
