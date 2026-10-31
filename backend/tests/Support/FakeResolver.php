<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Security\HostResolverInterface;

/** DNS ohne Netz: feste Zuordnung Host → Adressen. */
final class FakeResolver implements HostResolverInterface
{
    /**
     * @param array<string, list<string>> $hosts
     */
    public function __construct(private array $hosts = [])
    {
    }

    public function set(string $host, string ...$ips): void
    {
        $this->hosts[$host] = array_values($ips);
    }

    public function resolve(string $host): array
    {
        return $this->hosts[strtolower($host)] ?? [];
    }
}
