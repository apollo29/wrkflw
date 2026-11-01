<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\AccessResolverInterface;
use WorkflowEngine\Rest\Actor;

/**
 * Jede Anfrage ist erlaubt, als anonymer Admin. Nur für Entwicklung und Demo; die Host-App
 * muss das ausdrücklich so einbauen.
 */
final readonly class OpenAccessResolver implements AccessResolverInterface
{
    public function resolve(ServerRequestInterface $request): Access
    {
        return new Access(new Actor('anonym', 'Ohne Anmeldung'), true);
    }
}
