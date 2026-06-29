<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Platzhalter fuer die Integration-Suite. Solange keine Test-Datenbank konfiguriert
 * ist (WF_DB_DSN leer), wird der Test uebersprungen statt rot. Echte Integrationstests
 * gegen MariaDB folgen in Phase 2.
 */
#[CoversNothing]
#[Group('integration')]
final class DatabaseSmokeTest extends TestCase
{
    public function testDatabaseConnectionWhenConfigured(): void
    {
        $dsn = getenv('WF_DB_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('WF_DB_DSN nicht gesetzt — Integrationstest uebersprungen.');
        }

        $pdo = new \PDO(
            $dsn,
            (string) (getenv('WF_DB_USER') ?: 'root'),
            (string) (getenv('WF_DB_PASS') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $stmt = $pdo->query('SELECT 1');
        self::assertNotFalse($stmt);
        self::assertSame('1', (string) $stmt->fetchColumn());
    }
}
