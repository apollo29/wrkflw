<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration;

use WorkflowEngine\Tests\Support\IntegrationTestCase;

/**
 * Prüft, dass die Test-Datenbank erreichbar ist und schema.sql eingespielt werden kann.
 */
final class DatabaseConnectionTest extends IntegrationTestCase
{
    public function testSchemaLoadsAndConnectionUsesUtf8mb4(): void
    {
        $result = $this->pdo()->query('SELECT @@character_set_database');
        self::assertNotFalse($result);
        self::assertSame('utf8mb4', $result->fetchColumn());
    }
}
