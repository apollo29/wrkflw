<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Rest\RestConnection;
use WorkflowEngine\Security\SecretRotation;
use WorkflowEngine\Security\SodiumSecretStore;
use WorkflowEngine\Tests\Support\InMemoryDataSourceStore;
use WorkflowEngine\Tests\Support\RestFixtures;

final class SecretRotationTest extends TestCase
{
    public function testReencryptsOnlyOldSecrets(): void
    {
        $store = new InMemoryDataSourceStore();
        $store->saveConnection(RestFixtures::connection(AuthType::Bearer, 'alt-token'));
        $store->saveConnection(new RestConnection('ohne', 'Ohne Anmeldung', 'https://api.example.org'));

        $newKey = SodiumSecretStore::generateKey();
        $rotated = new SodiumSecretStore($newKey, [RestFixtures::KEY]);

        self::assertSame(1, (new SecretRotation($store, $rotated))->run());
        self::assertSame(0, (new SecretRotation($store, $rotated))->run(), 'zweiter Lauf ohne Wirkung');

        $cipher = (string) $store->findConnection('antrag-api')?->encryptedSecret;
        self::assertSame('alt-token', (new SodiumSecretStore($newKey))->decrypt($cipher)->reveal(), 'ohne alten Schlüssel lesbar');
    }
}
