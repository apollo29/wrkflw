<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Exception\WorkflowException;

/**
 * Bringt eine Datenbank auf den Stand von v3.
 *
 * - Leere Datenbank: `schema.sql` einspielen, alle Migrationen als angewendet vermerken.
 * - 2.x-Datenbank (`wf_definition` vorhanden, `wf_schema_version` nicht): alle Migrationen
 *   in Reihenfolge einspielen. Die 2.x-Migrationen sind idempotent, die v3-Migration legt
 *   `wf_schema_version` an.
 * - v3-Datenbank: nur fehlende Migrationen einspielen.
 *
 * Jede Migration läuft genau einmal. DDL in MariaDB ist nicht transaktional; eine
 * abgebrochene Migration wird deshalb nicht vermerkt und beim nächsten Lauf wiederholt
 * (die Migrationen sind wiederholbar formuliert).
 */
final readonly class SchemaMigrator
{
    private const string FRESH = 'schema.sql';

    public function __construct(
        private \PDO $pdo,
        private string $schemaFile,
        private string $migrationsDir,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @return list<string> was angewendet wurde (`schema.sql` oder Dateinamen der Migrationen)
     */
    public function migrate(): array
    {
        $tables = $this->tables();

        if (!in_array('wf_definition', $tables, true)) {
            $this->apply($this->read($this->schemaFile));
            foreach ($this->migrationFiles() as $name => $path) {
                $this->mark($name);
            }

            return [self::FRESH];
        }

        $applied = in_array('wf_schema_version', $tables, true) ? $this->appliedMigrations() : [];
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS wf_schema_version (
                name VARCHAR(128) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $done = [];
        foreach ($this->migrationFiles() as $name => $path) {
            if (in_array($name, $applied, true)) {
                continue;
            }
            $this->apply($this->read($path));
            $this->mark($name);
            $done[] = $name;
        }

        return $done;
    }

    /**
     * @return list<string>
     */
    public function appliedMigrations(): array
    {
        $result = $this->pdo->query('SELECT name FROM wf_schema_version ORDER BY name');
        if ($result === false) {
            return [];
        }

        return array_values(array_filter($result->fetchAll(\PDO::FETCH_COLUMN), is_string(...)));
    }

    /**
     * @return array<string, string> Dateiname => Pfad, sortiert
     */
    private function migrationFiles(): array
    {
        $files = glob(rtrim($this->migrationsDir, '/') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $out = [];
        foreach ($files as $path) {
            $out[basename($path)] = $path;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $result = $this->pdo->query('SHOW TABLES');

        return $result === false ? [] : array_values(array_filter($result->fetchAll(\PDO::FETCH_COLUMN), is_string(...)));
    }

    private function mark(string $name): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO wf_schema_version (name) VALUES (?)')->execute([$name]);
    }

    private function read(string $path): string
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new WorkflowException("SQL-Datei nicht lesbar: {$path}");
        }

        return $sql;
    }

    private function apply(string $sql): void
    {
        $lines = array_filter(
            preg_split('/\r?\n/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--'),
        );
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }
}
