<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

/**
 * Schutz vor Anfragen an interne Dienste (SSRF, F-136).
 *
 * Erlaubt sind nur HTTPS-Adressen ohne Zugangsdaten in der URL, deren Host ausschliesslich
 * auf öffentliche Adressen auflöst. Gesperrt sind private, Loopback-, Link-Local-,
 * CGNAT-, reservierte, Dokumentations-, Multicast- und Broadcast-Bereiche sowie die
 * IPv6-Entsprechungen; in IPv6 eingebettete IPv4-Adressen werden mitgeprüft. Optional
 * beschränkt die Host-App die Ziele auf eine Liste.
 *
 * Der Aufrufer sendet an die zurückgegebene IP (`SafeHttpClient`), damit eine zweite
 * DNS-Auflösung nicht auf ein internes Ziel zeigen kann (DNS-Rebinding).
 */
final readonly class UrlGuard
{
    private const array BLOCKED_V4 = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];

    private const array BLOCKED_V6 = [
        '::/128', '::1/128', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /**
     * @param list<string>|null $allowedHosts exakte Hosts oder `*.domain`; null = alle öffentlichen
     */
    public function __construct(
        private HostResolverInterface $resolver = new DnsHostResolver(),
        private ?array $allowedHosts = null,
    ) {
    }

    /**
     * @throws BlockedUrlException mit Begründung
     */
    public function check(string $url): CheckedUrl
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            throw new BlockedUrlException("Die Adresse '{$url}' ist ungültig.");
        }
        if (strtolower($parts['scheme'] ?? '') !== 'https') {
            throw new BlockedUrlException('Nur Adressen mit https:// sind erlaubt.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BlockedUrlException('Zugangsdaten gehören nicht in die Adresse, sondern in die Anmeldung der Verbindung.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? 443;

        if ($this->allowedHosts !== null && !$this->isAllowed($host)) {
            throw new BlockedUrlException("Der Host '{$host}' ist für REST-Verbindungen nicht freigegeben.");
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);
        if ($ips === []) {
            throw new BlockedUrlException("Der Host '{$host}' konnte nicht aufgelöst werden.");
        }
        foreach ($ips as $ip) {
            if (self::isInternal($ip)) {
                throw new BlockedUrlException("Der Host '{$host}' zeigt auf eine interne oder nicht öffentliche Adresse.");
            }
        }

        return new CheckedUrl($url, $host, $port, $ips[0]);
    }

    public static function isInternal(string $ip): bool
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return true;
        }

        if (strlen($binary) === 16) {
            // IPv4-mapped (::ffff:0:0/96), IPv4-kompatibel (::/96) und NAT64 (64:ff9b::/96): die IPv4 zählt.
            $prefix = substr($binary, 0, 12);
            if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === "\x00\x64\xff\x9b" . str_repeat("\0", 8)
                || ($prefix === str_repeat("\0", 12) && substr($binary, 12) !== "\0\0\0\0" && substr($binary, 12) !== "\0\0\0\1")) {
                $v4 = inet_ntop(substr($binary, 12));

                return $v4 === false || self::isInternal($v4);
            }
            foreach (self::BLOCKED_V6 as $cidr) {
                if (self::inRange($binary, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        if ($ip === '255.255.255.255') {
            return true;
        }
        foreach (self::BLOCKED_V4 as $cidr) {
            if (self::inRange($binary, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inRange(string $binary, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr);
        $net = inet_pton($network);
        if ($net === false || strlen($net) !== strlen($binary)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (substr($binary, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private function isAllowed(string $host): bool
    {
        foreach ($this->allowedHosts ?? [] as $allowed) {
            $allowed = strtolower($allowed);
            if ($allowed === $host || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }
}
