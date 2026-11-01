<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Datenquellen nur mit Admin-Recht (F-133); sonst 403. */
final readonly class RequireAdminMiddleware implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!JsonIo::access($request)->admin) {
            return JsonIo::error($this->responses->createResponse(), 403, 'forbidden', 'Für Datenquellen ist das Admin-Recht nötig.');
        }

        return $handler->handle($request);
    }
}
