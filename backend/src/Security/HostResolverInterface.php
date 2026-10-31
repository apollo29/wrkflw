<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

/** Löst einen Hostnamen in IP-Adressen auf (austauschbar für Tests). */
interface HostResolverInterface
{
    /**
     * @return list<string> IPv4- und IPv6-Adressen; leer, wenn nicht auflösbar
     */
    public function resolve(string $host): array;
}
