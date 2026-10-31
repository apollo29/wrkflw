<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\DataModel;

/**
 * Liest und schreibt die Daten eines Datenmodells (F-129 bis F-132). Die Persistenz weiss,
 * WO die Daten liegen; was erlaubt ist, entscheidet das Datenmodell vorher im Gateway.
 *
 * Werte gehen nach Feldnamen hinein und heraus; die Persistenz übersetzt in ihre eigenen
 * Namen (`Field::storageName()`) und wendet die Teilmenge des Modells bei jedem Vorgang an.
 * Was ausserhalb der Teilmenge liegt, gibt es nicht. Löschen gibt es nicht (F-36).
 *
 * Technische Fehler (Verbindung, Zeitüberschreitung) werden als Exception geworfen und
 * von der Engine wiederholt.
 */
interface PersistenceInterface
{
    /**
     * @return array<string, mixed>|null Werte nach Feldnamen, oder null wenn nicht gefunden
     */
    public function load(DataModel $model, string $id): ?array;

    /**
     * @param list<Condition> $conditions mit UND verknüpft
     *
     * @return list<array<string, mixed>>
     */
    public function find(DataModel $model, array $conditions, int $limit): array;

    /**
     * Ändert genau einen Datensatz.
     *
     * @param array<string, mixed> $values nur die geänderten Felder, normalisiert
     *
     * @return bool false, wenn es den Datensatz (in der Teilmenge) nicht gibt
     */
    public function update(DataModel $model, string $id, array $values, ChangeOrigin $origin): bool;

    /**
     * Legt genau einen Datensatz an und gibt seine ID zurück.
     *
     * @param array<string, mixed> $values normalisiert
     */
    public function create(DataModel $model, ?string $id, array $values, ChangeOrigin $origin): string;
}
