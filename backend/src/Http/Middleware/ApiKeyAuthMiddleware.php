<?php

declare(strict_types=1);

namespace WorkflowEngine\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Platzhalter-Authentifizierung per statischem API-Key im Authorization-Header
 * ("Authorization: Bearer <key>"). Bewusst einfach gehalten und austauschbar:
 * die Host-App kann jede beliebige PSR-15-Middleware stattdessen verwenden.
 */
final class ApiKeyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $scheme = 'Bearer',
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $provided = $this->extractKey($request);

        if ($provided === null || !hash_equals($this->apiKey, $provided)) {
            return $this->unauthorized();
        }

        return $handler->handle($request);
    }

    private function extractKey(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        $prefix = $this->scheme . ' ';

        if ($header === '' || !str_starts_with($header, $prefix)) {
            return null;
        }

        return substr($header, strlen($prefix));
    }

    private function unauthorized(): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode([
            'error' => ['code' => 'unauthorized', 'message' => 'Fehlender oder ungueltiger API-Key.'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', $this->scheme);
    }
}
