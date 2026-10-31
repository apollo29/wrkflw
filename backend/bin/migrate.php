<?php

declare(strict_types=1);

/*
 * Bringt die Datenbank auf den Stand von v3: legt sie neu an oder migriert eine
 * 2.x-Datenbank (siehe docs/migration-v3.md). Mehrfach ausführbar.
 *
 * Verbindung über WF_DB_DSN (+ WF_DB_USER, WF_DB_PASS) oder DB_HOST, DB_PORT, DB_NAME,
 * DB_USER, DB_PASS.
 */

use WorkflowEngine\Persistence\SchemaMigrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$env = static fn (string $name, string $default = ''): string => is_string($v = getenv($name)) && $v !== '' ? $v : $default;

$dsn = $env('WF_DB_DSN') !== ''
    ? $env('WF_DB_DSN')
    : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', '3306'), $env('DB_NAME', 'workflow'));
$user = $env('WF_DB_USER', $env('DB_USER', 'root'));
$pass = $env('WF_DB_PASS', $env('DB_PASS'));

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $migrator = new SchemaMigrator($pdo, dirname(__DIR__) . '/schema.sql', dirname(__DIR__) . '/migrations');
    $applied = $migrator->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration fehlgeschlagen: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($applied === []) {
    echo 'Datenbank ist bereits auf dem neuesten Stand.' . PHP_EOL;
} else {
    foreach ($applied as $name) {
        echo "Angewendet: {$name}" . PHP_EOL;
    }
}
