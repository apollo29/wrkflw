<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Security\BlockedUrlException;
use WorkflowEngine\Security\UrlGuard;
use WorkflowEngine\Tests\Support\FakeResolver;

/** Verbindungen nur über HTTPS und nie an interne Adressen (F-136). */
final class UrlGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function blockedAddresses(): iterable
    {
        foreach ([
            '127.0.0.1', '10.1.2.3', '172.16.0.5', '192.168.1.1', '169.254.169.254', '100.64.0.1',
            '0.0.0.0', '224.0.0.1', '255.255.255.255', '192.0.2.10', '198.18.0.1',
            '::1', 'fc00::1', 'fd12:3456::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:10.0.0.1', '::', 'ff02::1',
        ] as $ip) {
            yield $ip => [$ip];
        }
    }

    #[DataProvider('blockedAddresses')]
    public function testInternalAddressesAreBlocked(string $ip): void
    {
        $guard = new UrlGuard(new FakeResolver(['api.example.org' => [$ip]]));

        $this->expectException(BlockedUrlException::class);
        $guard->check('https://api.example.org/v1');
    }

    public function testPublicHostsPassAndTheResolvedAddressIsReturned(): void
    {
        $guard = new UrlGuard(new FakeResolver(['api.example.org' => ['93.184.216.34', '2606:2800:220:1::1']]));

        $checked = $guard->check('https://api.example.org:8443/v1/antraege?x=1');

        self::assertSame('api.example.org', $checked->host);
        self::assertSame(8443, $checked->port);
        self::assertSame('93.184.216.34', $checked->ip);
    }

    public function testAHostWithOneInternalAddressIsBlocked(): void
    {
        $guard = new UrlGuard(new FakeResolver(['mixed.example.org' => ['93.184.216.34', '10.0.0.1']]));

        $this->expectException(BlockedUrlException::class);
        $guard->check('https://mixed.example.org');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'http' => ['http://api.example.org', 'https://'];
        yield 'ohne Host' => ['https:///pfad', 'Adresse'];
        yield 'Zugangsdaten' => ['https://user:pw@api.example.org', 'Zugangsdaten'];
        yield 'IP-Literal intern' => ['https://127.0.0.1/', 'interne'];
        yield 'IPv6-Literal intern' => ['https://[::1]/', 'interne'];
        yield 'nicht auflösbar' => ['https://gibt-es-nicht.example.org', 'aufgelöst'];
        yield 'localhost' => ['https://localhost/', 'interne'];
    }

    #[DataProvider('invalidUrls')]
    public function testInvalidUrlsAreRejectedWithAReason(string $url, string $reason): void
    {
        $guard = new UrlGuard(new FakeResolver(['api.example.org' => ['93.184.216.34'], 'localhost' => ['127.0.0.1']]));

        try {
            $guard->check($url);
            self::fail('Nicht abgelehnt.');
        } catch (BlockedUrlException $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function testTheHostAppCanRestrictTargetsToAnAllowlist(): void
    {
        $guard = new UrlGuard(
            new FakeResolver(['api.example.org' => ['93.184.216.34'], 'other.example.net' => ['93.184.216.35']]),
            allowedHosts: ['api.example.org', '*.partner.example.com'],
        );

        self::assertSame('api.example.org', $guard->check('https://API.example.org/')->host);
        $this->expectException(BlockedUrlException::class);
        $this->expectExceptionMessage('nicht freigegeben');
        $guard->check('https://other.example.net/');
    }
}
