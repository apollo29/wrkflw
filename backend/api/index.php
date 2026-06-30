<?php

declare(strict_types=1);

/**
 * HTTP-Einstieg der Workflow-Engine (Slim 4).
 *
 * Verbindung und Wiring kommen aus examples/bootstrap.php; die Routen, das
 * JSON-Fehlerformat und die optionale Auth-Middleware baut die ApiFactory.
 *
 * Auth aktivieren, indem die Umgebungsvariable WF_API_KEY gesetzt wird
 * (Clients senden dann "Authorization: Bearer <key>").
 */

require __DIR__ . '/../vendor/autoload.php';

use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\Middleware\ApiKeyAuthMiddleware;

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

$apiKey = getenv('WF_API_KEY');
$auth = is_string($apiKey) && $apiKey !== '' ? new ApiKeyAuthMiddleware($apiKey) : null;

$app = ApiFactory::create($engine, $repo, $auth);
$app->run();
