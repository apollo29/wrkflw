<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/**
 * Grenzen und Freigaben der Host-App für REST-Datenquellen (F-136, F-137, F-144).
 */
final readonly class RestOptions
{
    /**
     * @param list<string>|null $allowedHosts erlaubte Ziele (exakt oder `*.domain`); null = alle öffentlichen
     * @param bool              $allowWrites  false sperrt Ändern und Anlegen über REST ganz
     */
    public function __construct(
        public ?array $allowedHosts = null,
        public bool $allowWrites = true,
        public float $timeoutSeconds = 10.0,
        public int $maxResponseBytes = 1_048_576,
        public int $maxRedirects = 3,
        public int $maxResults = 100,
    ) {
    }
}
