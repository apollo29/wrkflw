<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

/** Auflösung über das DNS des Servers (A und AAAA). */
final class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        $aaaa = @dns_get_record($host, DNS_AAAA);
        foreach ($aaaa === false ? [] : $aaaa as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
