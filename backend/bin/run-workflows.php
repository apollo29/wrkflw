<?php

declare(strict_types=1);

/**
 * Cron-Einstieg. Beispiel-Crontab (jede Minute):
 *   * * * * * php /pfad/backend/bin/run-workflows.php >> /var/log/wf.log 2>&1
 *
 * Verarbeitet faellige Timer-Instanzen (nebenlaeufigkeitssicher) und prueft
 * datengetriebene Trigger. Mehrere parallele Laeufe sind sicher.
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

require __DIR__ . '/../examples/bootstrap.php';
[$engine, $runner, $repo] = buildEngine($pdo);

// Datengetriebene Trigger der Host-App registrieren:
// $runner->addTrigger(new OverdueInvoiceTrigger($dataProvider));

$stats = $runner->tick();
printf("[%s] woken=%d started=%d errors=%d\n", date('c'), $stats['woken'], $stats['started'], $stats['errors']);
