<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Basis fuer Integrationstests gegen ein echtes MariaDB.
 *
 * Verbindung kommt aus WF_DB_DSN / WF_DB_USER / WF_DB_PASS. Ist WF_DB_DSN leer,
 * werden die Tests uebersprungen (so bleibt die Suite ohne DB gruen).
 *
 * Vor jedem Test wird die in der DSN genannte Datenbank angelegt (falls noetig)
 * und das Schema aus schema.sql frisch eingespielt - jeder Test startet sauber.
 */
abstract class IntegrationTestCase extends TestCase
{
    private ?\PDO $pdo = null;

    protected function setUp(): void
    {
        $dsn = (string) (getenv('WF_DB_DSN') ?: '');
        if ($dsn === '') {
            self::markTestSkipped('WF_DB_DSN nicht gesetzt - Integrationstest uebersprungen.');
        }

        $user = (string) (getenv('WF_DB_USER') ?: 'root');
        $pass = (string) (getenv('WF_DB_PASS') ?: '');

        $this->ensureDatabaseExists($dsn, $user, $pass);

        $this->pdo = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $this->loadSchema($this->pdo);
    }

    protected function pdo(): \PDO
    {
        if ($this->pdo === null) {
            throw new \LogicException('PDO nicht initialisiert - setUp() nicht gelaufen?');
        }

        return $this->pdo;
    }

    /**
     * Eigene, unabhaengige Verbindung (z. B. um in Nebenlaeufigkeits-Tests
     * eine Zeile aus einer zweiten Transaktion heraus zu sperren).
     */
    protected function newConnection(): \PDO
    {
        return new \PDO(
            (string) (getenv('WF_DB_DSN') ?: ''),
            (string) (getenv('WF_DB_USER') ?: 'root'),
            (string) (getenv('WF_DB_PASS') ?: ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private function ensureDatabaseExists(string $dsn, string $user, string $pass): void
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $m) !== 1) {
            return; // keine dbname in der DSN -> nichts anzulegen
        }
        $dbName = $m[1];

        $serverDsn = (string) preg_replace('/;?dbname=[^;]+/', '', $dsn);
        $server = new \PDO($serverDsn, $user, $pass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $server->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private function loadSchema(\PDO $pdo): void
    {
        // Frisch: bestehende Tabellen entfernen (FK-Reihenfolge beachten).
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['wf_history', 'wf_instance', 'wf_definition', 'wf_template'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $path = dirname(__DIR__, 2) . '/schema.sql';
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("schema.sql nicht lesbar: {$path}");
        }

        foreach ($this->splitStatements($sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    /**
     * Zerlegt eine SQL-Datei in einzelne Statements (Kommentarzeilen entfernt).
     *
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }
            $clean[] = $line;
        }

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
