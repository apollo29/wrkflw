<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Basis für Integrationstests gegen ein echtes MariaDB.
 *
 * Die Verbindung kommt aus WF_DB_DSN / WF_DB_USER / WF_DB_PASS. Ist WF_DB_DSN leer,
 * werden die Tests übersprungen, damit die Suite ohne Datenbank grün bleibt.
 *
 * Vor jedem Test wird die Datenbank aus der DSN angelegt (falls nötig), alle
 * vorhandenen Tabellen werden entfernt und schema.sql frisch eingespielt.
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $dsn = self::env('WF_DB_DSN', '');
        if ($dsn === '') {
            self::markTestSkipped('WF_DB_DSN nicht gesetzt – Integrationstest übersprungen.');
        }

        $this->ensureDatabaseExists($dsn);
        $this->pdo = $this->newConnection();
        $this->resetSchema($this->pdo);
    }

    protected function pdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new \LogicException('PDO nicht initialisiert – setUp() nicht gelaufen?');
        }

        return $this->pdo;
    }

    /**
     * Eigene, unabhängige Verbindung, etwa um in Nebenläufigkeits-Tests eine
     * Zeile aus einer zweiten Transaktion heraus zu sperren.
     */
    protected function newConnection(): \PDO
    {
        return new \PDO(
            self::env('WF_DB_DSN', ''),
            self::env('WF_DB_USER', 'root'),
            self::env('WF_DB_PASS', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function ensureDatabaseExists(string $dsn): void
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m) !== 1) {
            return;
        }

        $server = new \PDO(
            (string) preg_replace('/;?dbname=[^;]+/', '', $dsn),
            self::env('WF_DB_USER', 'root'),
            self::env('WF_DB_PASS', ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $server->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $m[1]),
        ));
    }

    private function resetSchema(\PDO $pdo): void
    {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query('SHOW TABLES');
        foreach ($tables === false ? [] : $tables->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            if (is_string($table)) {
                $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '', $table)));
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $path = dirname(__DIR__, 2) . '/schema.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("schema.sql nicht lesbar: {$path}");
        }

        foreach (self::splitStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Zerlegt eine SQL-Datei in einzelne Statements (Kommentarzeilen entfernt).
     *
     * @return list<string>
     */
    private static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $clean = array_filter($lines, static fn (string $line): bool => !str_starts_with(ltrim($line), '--'));

        $statements = [];
        foreach (explode(';', implode("\n", $clean)) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }
}
