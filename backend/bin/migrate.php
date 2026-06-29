<?php

declare(strict_types=1);

/**
 * Wendet das Schema (schema.sql) auf die konfigurierte Datenbank an.
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

$sql = file_get_contents(__DIR__ . '/../schema.sql');
if ($sql === false) {
    fwrite(STDERR, "schema.sql nicht lesbar\n");
    exit(1);
}

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

printf("Schema angewendet (%d Statements) auf %s\n", $count, getenv('DB_NAME') ?: 'workflow');
