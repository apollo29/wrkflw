<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/** Ergebnis von «Verbindung testen»: nur Statuscode und Dauer, nie Inhalte (F-139). */
final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $ok,
        public ?int $status,
        public int $durationMs,
        public ?string $message = null,
    ) {
    }
}
