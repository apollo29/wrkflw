<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\DataWriteCatalogInterface;
use WorkflowEngine\Contracts\DataWriterInterface;

/**
 * In-Memory-Fake des DataWriters (+ Schreib-Katalog) fuer Unit-Tests.
 *
 * Verhaelt sich wie ein ordentlicher Host: nur bekannte Entitaeten, nur
 * bestehende Datensaetze, nur erlaubte Spalten — und `false`, wenn davon
 * nichts uebrig bleibt.
 */
final class InMemoryDataWriter implements DataWriterInterface, DataWriteCatalogInterface
{
    /** @var array<string,array<string,array<string,scalar|null>>> entity => id => row */
    private array $rows = [];

    /** @var array<string,list<string>> entity => erlaubte Spalten */
    private array $erlaubt = [];

    /** @var list<array{entity:string,id:string,values:array<string,scalar|null>,herkunft:string,anlegen:bool}> */
    public array $calls = [];

    /**
     * @param array<string,scalar|null> $row
     * @param list<string>              $erlaubteSpalten Leer = jede Spalte der Zeile
     */
    public function seed(string $entity, string $id, array $row, array $erlaubteSpalten = []): void
    {
        $this->rows[$entity][$id] = $row;
        $this->erlaubt[$entity] = $erlaubteSpalten !== [] ? $erlaubteSpalten : array_keys($row);
    }

    /** @return array<string,scalar|null>|null */
    public function row(string $entity, string $id): ?array
    {
        return $this->rows[$entity][$id] ?? null;
    }

    /** Ein einzelner Wert — ohne Umweg ueber eine Zeile, die es nicht geben muss. */
    public function wert(string $entity, string $id, string $spalte): string|int|float|bool|null
    {
        return $this->rows[$entity][$id][$spalte] ?? null;
    }

    public function write(
        string $entity,
        string|int $id,
        array $values,
        string $herkunft,
        bool $anlegen = false,
    ): bool {
        $this->calls[] = [
            'entity' => $entity,
            'id' => (string) $id,
            'values' => $values,
            'herkunft' => $herkunft,
            'anlegen' => $anlegen,
        ];

        if (!isset($this->rows[$entity][(string) $id])) {
            if (!$anlegen || !isset($this->erlaubt[$entity])) {
                return false;
            }
            // Angelegt wird mit genau den erlaubten Spalten — wie ein Host, der
            // nichts erfindet, was nicht in der Freigabe steht.
            $this->rows[$entity][(string) $id] = [];
        }

        $erlaubt = $this->erlaubt[$entity] ?? [];
        $geschrieben = 0;
        foreach ($values as $spalte => $wert) {
            if (!in_array($spalte, $erlaubt, true)) {
                continue;
            }
            $this->rows[$entity][(string) $id][$spalte] = $wert;
            $geschrieben++;
        }

        return $geschrieben > 0;
    }

    public function writableEntities(): array
    {
        $out = [];
        foreach ($this->erlaubt as $entity => $felder) {
            $out[] = ['entity' => (string) $entity, 'label' => (string) $entity, 'fields' => $felder];
        }

        return $out;
    }
}
