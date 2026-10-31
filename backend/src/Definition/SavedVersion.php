<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/** Ergebnis des Speicherns einer Definition. */
final readonly class SavedVersion
{
    public function __construct(
        public string $id,
        public int $version,
        public DefinitionStatus $status,
        public bool $newVersion,
    ) {
    }
}
