<?php

declare(strict_types=1);

/**
 * Spielt eine Workflow-Definition aus einer JSON-Datei in wf_definition ein.
 * Validiert die Definition vorher (Struktur + Graph).
 *
 *   php bin/seed-definition.php examples/onboarding.json
 */

require __DIR__ . '/../vendor/autoload.php';

use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\WorkflowDefinition;

$path = $argv[1] ?? null;
if (!is_string($path) || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/seed-definition.php <pfad-zur-definition.json>\n");
    exit(1);
}

$json = file_get_contents($path);
if ($json === false) {
    fwrite(STDERR, "Datei nicht lesbar: {$path}\n");
    exit(1);
}

/** @var array<string,mixed> $data */
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$def = WorkflowDefinition::fromArray($data);
(new DefinitionValidator())->validate($def);

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    getenv('DB_HOST') ?: '127.0.0.1',
    getenv('DB_PORT') ?: '3306',
    getenv('DB_NAME') ?: 'workflow',
);
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$stmt = $pdo->prepare(
    "INSERT INTO wf_definition (id, version, name, definition, active, status)
     VALUES (:id, :v, :name, :def, 1, 'active')
     ON DUPLICATE KEY UPDATE
         name = VALUES(name), definition = VALUES(definition), active = 1, status = 'active'"
);
$stmt->execute([
    ':id' => $def->id,
    ':v' => $def->version,
    ':name' => $def->name,
    ':def' => $json,
]);

printf("Definition '%s' v%d eingespielt.\n", $def->id, $def->version);
