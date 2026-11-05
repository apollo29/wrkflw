<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fehlerformat nur für die eingehängte Gruppe ({@see ApiFactory::mount()}): Ausnahmen der
 * Engine werden zu `{error: {code, message}}`, die Host-App behält ihren eigenen Handler.
 */
final readonly class ErrorBoundaryMiddleware implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (\Throwable $e) {
            return ErrorMapper::respond($e, $this->responses->createResponse());
        }
    }
}
