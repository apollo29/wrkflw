<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use WorkflowEngine\Contracts\AccessResolverInterface;

/** Ermittelt den Zugriff über den Port und legt ihn als Request-Attribut ab; sonst 401. */
final readonly class AccessMiddleware implements MiddlewareInterface
{
    public function __construct(
        private AccessResolverInterface $resolver,
        private ResponseFactoryInterface $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $access = $this->resolver->resolve($request);
        if ($access === null) {
            return JsonIo::error($this->responses->createResponse(), 401, 'unauthorized', 'Anmeldung erforderlich.')
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        return $handler->handle($request->withAttribute(Access::ATTRIBUTE, $access));
    }
}
