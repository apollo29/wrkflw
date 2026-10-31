<?php

declare(strict_types=1);

/*
 * Ein Hintergrundlauf (Cron, jede Minute; siehe docs/crontab.example).
 *
 * WF_BOOTSTRAP zeigt auf eine PHP-Datei der Host-App, die einen fertig verdrahteten
 * WorkflowEngine\Engine\WorkflowRunner zurückgibt – mit ihren Aktionen, Datenmodellen,
 * Mailer und Triggern. Die Engine selbst kennt die Host-App nicht (ADR 1).
 *
 *     // config/wrkflw-runner.php
 *     return $container->get(WorkflowEngine\Engine\WorkflowRunner::class);
 */

use WorkflowEngine\Engine\WorkflowRunner;

$autoload = [dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 4) . '/autoload.php'];
foreach ($autoload as $file) {
    if (is_file($file)) {
        require $file;
        break;
    }
}

$bootstrap = getenv('WF_BOOTSTRAP');
if (!is_string($bootstrap) || !is_file($bootstrap)) {
    fwrite(STDERR, 'WF_BOOTSTRAP muss auf eine PHP-Datei zeigen, die einen WorkflowRunner zurückgibt.' . PHP_EOL);
    exit(2);
}

$runner = require $bootstrap;
if (!$runner instanceof WorkflowRunner) {
    fwrite(STDERR, "{$bootstrap} hat keinen WorkflowRunner zurückgegeben." . PHP_EOL);
    exit(2);
}

$stats = $runner->tick();
printf(
    "[%s] geweckt: %d, gestartet: %d, gesperrt: %d, Fehler: %d\n",
    date('c'),
    $stats->woken,
    $stats->started,
    $stats->locked,
    $stats->errors,
);
exit($stats->errors > 0 ? 1 : 0);
