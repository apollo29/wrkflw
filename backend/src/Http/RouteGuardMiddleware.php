<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Rollen der Host-App je Route ({@see ApiFactory::mount()}): läuft nach dem Zugriff und
 * fragt den Guard mit dem Routen-Schlüssel, z. B. «POST /instances/{id}/events»; sonst 403.
 */
final readonly class RouteGuardMiddleware implements MiddlewareInterface
{
    /**
     * @param \Closure(string, Access): bool $guard
     */
    public function __construct(
        private string $route,
        private \Closure $guard,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!($this->guard)($this->route, JsonIo::access($request))) {
            return JsonIo::error($this->responses->createResponse(), 403, 'forbidden', 'Dafür fehlt das Recht.');
        }

        return $handler->handle($request);
    }
}
