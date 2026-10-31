<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Model\FieldType;

/** Ein Feld eines REST-Datenmodells: Name, Bezeichnung, Typ, Pfad in der Antwort, schreibbar. */
final readonly class RestFieldSpec
{
    public function __construct(
        public string $name,
        public string $label,
        public FieldType $type,
        public string $path,
        public bool $writable = false,
    ) {
    }
}
