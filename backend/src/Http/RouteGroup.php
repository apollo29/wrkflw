<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Slim\Interfaces\RouteCollectorProxyInterface;

/**
 * Inhalt einer Slim-Gruppe als aufrufbares Objekt statt als Closure.
 *
 * Slim bindet Gruppen-Closures an den Container der Host-App (`Closure::bindTo`).
 * Eine Closure aus einer statischen Methode ist selbst statisch und lässt sich nicht
 * binden; der Aufbau der Routentabelle scheiterte damit in jeder Host-App mit
 * Container (3.1.0). Ein Objekt bindet Slim nicht.
 *
 * @internal
 */
final readonly class RouteGroup
{
    /**
     * @param \Closure(RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null>): void $routes
     */
    public function __construct(private \Closure $routes)
    {
    }

    /**
     * @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group
     */
    public function __invoke(RouteCollectorProxyInterface $group): void
    {
        ($this->routes)($group);
    }
}
