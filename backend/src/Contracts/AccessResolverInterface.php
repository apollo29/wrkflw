<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Http\Access;

/**
 * Wer fragt an, und was darf die Person (F-104)? Anmeldung und Rechte bleiben bei der
 * Host-App; die Engine fragt nur über diesen Port (ADR 11).
 */
interface AccessResolverInterface
{
    /** null = nicht angemeldet (401). */
    public function resolve(ServerRequestInterface $request): ?Access;
}
