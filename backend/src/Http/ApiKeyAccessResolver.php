<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\AccessResolverInterface;

/**
 * Zugriff über einen festen Schlüssel im Header `Authorization: Bearer <key>` (aus 2.x).
 * Wer den Schlüssel hat, handelt als Actor «api» mit Admin-Recht. Für echte Personen im
 * Protokoll liefert die Host-App einen eigenen Resolver.
 */
final readonly class ApiKeyAccessResolver implements AccessResolverInterface
{
    public function __construct(
        #[\SensitiveParameter] private string $apiKey,
        private string $scheme = 'Bearer',
        private Access $access = new Access(new \WorkflowEngine\Rest\Actor('api', 'API-Schlüssel'), true),
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Der API-Schlüssel darf nicht leer sein.');
        }
    }

    public function resolve(ServerRequestInterface $request): ?Access
    {
        $header = $request->getHeaderLine('Authorization');
        $prefix = $this->scheme . ' ';
        if (!str_starts_with($header, $prefix)) {
            return null;
        }

        return hash_equals($this->apiKey, substr($header, strlen($prefix))) ? $this->access : null;
    }
}
