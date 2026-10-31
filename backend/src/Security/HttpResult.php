<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

/** Antwort einer geprüften HTTP-Anfrage. */
final readonly class HttpResult
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public int $durationMs,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
