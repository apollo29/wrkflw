<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

/** Eine geprüfte Adresse mit der Adresse, an die die Anfrage gehen darf. */
final readonly class CheckedUrl
{
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $ip,
    ) {
    }
}
