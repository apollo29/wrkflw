<?php

declare(strict_types=1);

/*
 * Schlüssel für Zugangsdaten verwalten (ADR 10).
 *
 *   php bin/rotate-secret-key.php --generate
 *       gibt einen neuen Schlüssel für WF_SECRET_KEY aus
 *
 *   WF_SECRET_KEY=<neu> WF_SECRET_KEYS_OLD=<alt> php bin/rotate-secret-key.php
 *       verschlüsselt alle Zugangsdaten mit dem neuen Schlüssel neu; danach kann der alte
 *       aus WF_SECRET_KEYS_OLD entfernt werden
 *
 * Datenbank wie bin/migrate.php (WF_DB_DSN oder DB_*).
 */

use WorkflowEngine\Persistence\PdoDataSourceStore;
use WorkflowEngine\Security\SecretRotation;
use WorkflowEngine\Security\SodiumSecretStore;

require dirname(__DIR__) . '/vendor/autoload.php';

if (in_array('--generate', $argv, true)) {
    echo SodiumSecretStore::generateKey() . PHP_EOL;
    exit(0);
}

$env = static fn (string $name, string $default = ''): string => is_string($v = getenv($name)) && $v !== '' ? $v : $default;
$dsn = $env('WF_DB_DSN') !== ''
    ? $env('WF_DB_DSN')
    : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('DB_HOST', '127.0.0.1'), $env('DB_PORT', '3306'), $env('DB_NAME', 'workflow'));

try {
    $pdo = new PDO($dsn, $env('WF_DB_USER', $env('DB_USER', 'root')), $env('WF_DB_PASS', $env('DB_PASS')), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $count = (new SecretRotation(new PdoDataSourceStore($pdo), SodiumSecretStore::fromEnvironment()))->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'Schlüsselwechsel fehlgeschlagen: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Neu verschlüsselt: {$count} Verbindung(en)." . PHP_EOL;
