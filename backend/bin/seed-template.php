<?php

declare(strict_types=1);

/**
 * Spielt ein Template aus einer JSON-Datei in wf_template ein (Upsert).
 *
 *   php bin/seed-template.php examples/welcome-page.template.json
 *
 * JSON-Form: { "id", "name", "type": "email"|"page", "subject", "body" }
 */

require __DIR__ . '/../vendor/autoload.php';

$path = $argv[1] ?? null;
if (!is_string($path) || !is_file($path)) {
    fwrite(STDERR, "Usage: php bin/seed-template.php <pfad-zum-template.json>\n");
    exit(1);
}

$json = file_get_contents($path);
if ($json === false) {
    fwrite(STDERR, "Datei nicht lesbar: {$path}\n");
    exit(1);
}

/** @var array<string,mixed> $data */
$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

$str = static function (string $key, string $default = '') use ($data): string {
    $value = $data[$key] ?? $default;

    return is_string($value) ? $value : $default;
};

$id = $str('id');
if ($id === '') {
    fwrite(STDERR, "Template braucht ein Feld 'id'.\n");
    exit(1);
}
$type = $str('type', 'email') === 'page' ? 'page' : 'email';

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
    'INSERT INTO wf_template (id, name, type, subject, body)
     VALUES (:id, :name, :type, :subject, :body)
     ON DUPLICATE KEY UPDATE
         name = VALUES(name), type = VALUES(type),
         subject = VALUES(subject), body = VALUES(body)'
);
$stmt->execute([
    ':id' => $id,
    ':name' => $str('name', $id),
    ':type' => $type,
    ':subject' => $str('subject'),
    ':body' => $str('body'),
]);

printf("Template '%s' (%s) eingespielt.\n", $id, $type);
