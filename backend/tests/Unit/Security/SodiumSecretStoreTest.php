<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Security\Secret;
use WorkflowEngine\Security\SecretException;
use WorkflowEngine\Security\SodiumSecretStore;

/** Zugangsdaten verschlüsselt ablegen, nie im Klartext zeigen (F-135). */
final class SodiumSecretStoreTest extends TestCase
{
    private static function key(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function testRoundTripWithRandomNonce(): void
    {
        $store = new SodiumSecretStore(self::key());

        $a = $store->encrypt('geheimes-token');
        $b = $store->encrypt('geheimes-token');

        self::assertStringStartsWith('v1:', $a);
        self::assertNotSame($a, $b, 'jede Verschlüsselung mit eigener Nonce');
        self::assertStringNotContainsString('geheimes-token', $a);
        self::assertSame('geheimes-token', $store->decrypt($a)->reveal());
    }

    public function testTamperingAndForeignKeysAreRejected(): void
    {
        $store = new SodiumSecretStore(self::key());
        $cipher = $store->encrypt('x');
        $tampered = substr($cipher, 0, -2) . (str_ends_with($cipher, 'A') ? 'B' : 'A') . substr($cipher, -1);

        foreach ([$tampered, (new SodiumSecretStore(self::key()))->encrypt('x'), 'kein-format'] as $bad) {
            try {
                $store->decrypt($bad);
                self::fail('Nicht abgelehnt: ' . $bad);
            } catch (SecretException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRotationKeepsOldSecretsReadableAndMarksThem(): void
    {
        $old = self::key();
        $legacy = (new SodiumSecretStore($old))->encrypt('alt');

        $rotated = new SodiumSecretStore(self::key(), [$old]);

        self::assertSame('alt', $rotated->decrypt($legacy)->reveal());
        self::assertTrue($rotated->needsReencryption($legacy));
        self::assertFalse($rotated->needsReencryption($rotated->encrypt('neu')));
    }

    public function testInvalidKeysAreRejected(): void
    {
        $this->expectException(SecretException::class);
        new SodiumSecretStore(base64_encode('zu kurz'));
    }

    public function testSecretsNeverLeakIntoStringsDumpsOrJson(): void
    {
        $secret = new Secret('geheim');

        self::assertSame('***', (string) $secret);
        self::assertSame('"***"', json_encode($secret));
        self::assertStringNotContainsString('geheim', print_r($secret, true));
        ob_start();
        var_dump($secret);
        self::assertStringNotContainsString('geheim', (string) ob_get_clean());
        self::assertSame('geheim', $secret->reveal());
    }
}
