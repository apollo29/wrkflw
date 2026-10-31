<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/** Ergebnis eines Speicherns. «Nichts geschrieben» ist ein Ergebnis, kein Fehler (F-38). */
final readonly class SaveResult
{
    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public bool $written,
        public string $id,
        public array $changedFields = [],
        public bool $created = false,
    ) {
    }
}
