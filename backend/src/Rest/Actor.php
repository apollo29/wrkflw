<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/** Wer eine Änderung vornimmt. Anmeldung und Rechte prüft die Host-App (F-104). */
final readonly class Actor
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
