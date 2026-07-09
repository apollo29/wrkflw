<?php

declare(strict_types=1);

/**
 * Bringt die Datenbank auf den aktuellen Stand:
 *   1) legt fehlende Tabellen aus schema.sql an (CREATE TABLE IF NOT EXISTS),
 *   2) wendet anschliessend alle inkrementellen Migrationen aus migrations/*.sql an
 *      (alphabetisch sortiert; jede Migration ist idempotent, z. B. ADD COLUMN
 *      IF NOT EXISTS), sodass auch bestehende Datenbanken nachgezogen werden.
 *
 * Verbindung aus den Umgebungsvariablen DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS.
 *
 *   php bin/migrate.php
 */

require __DIR__ . '/../vendor/autoload.php';

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'workflow',
);

$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

/**
 * Zerlegt eine SQL-Datei in einzelne Statements (Kommentarzeilen entfernt) und
 * fuehrt sie aus. Gibt die Anzahl ausgefuehrter Statements zurueck.
 */
$applySql = static function (PDO $pdo, string $sql): int {
    $lines = array_filter(
        preg_split('/\r?\n/', $sql) ?: [],
        static fn (string $l): bool => !str_starts_with(ltrim($l), '--'),
    );

    $count = 0;
    foreach (explode(';', implode("\n", $lines)) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
            $count++;
        }
    }

    return $count;
};

// 1) Basis-Schema.
$schema = file_get_contents(__DIR__ . '/../schema.sql');
if ($schema === false) {
    fwrite(STDERR, "schema.sql nicht lesbar\n");
    exit(1);
}
$count = $applySql($pdo, $schema);
printf("Schema angewendet (%d Statements) auf %s\n", $count, getenv('DB_NAME') ?: 'workflow');

// 2) Inkrementelle Migrationen.
$files = glob(__DIR__ . '/../migrations/*.sql');
if ($files === false) {
    $files = [];
}
sort($files);

foreach ($files as $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, 'Migration nicht lesbar: ' . basename($file) . "\n");
        exit(1);
    }
    $applySql($pdo, $sql);
    printf("Migration angewendet: %s\n", basename($file));
}
