<?php

declare(strict_types=1);

/*
 * Beispieldaten 9.13 für die Demo mit echtem Backend:
 *
 *   WF_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=workflow_demo;charset=utf8mb4' php demo/seed.php
 *
 * Legt Schema und Demo-Tabelle an und ersetzt ALLE Daten der Engine in dieser Datenbank.
 * Deshalb nur, wenn der Datenbankname «demo» enthält oder WF_DEMO_SEED_FORCE=1 gesetzt ist.
 * Mehrfach ausführbar: jeder Lauf beginnt von vorn.
 */

use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionStatus;
use WorkflowEngine\Persistence\SchemaMigrator;
use WorkflowEngine\Rest\Actor;
use WorkflowEngine\Rest\AdminTrigger;
use WorkflowEngine\Rest\AuditEntry;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Rest\RestConnection;
use WorkflowEngine\Rest\RestModelSpec;

require_once __DIR__ . '/services.php';

/**
 * Liest eine Liste von Objekten aus `demo/data/` und prüft die Form.
 *
 * @return list<array<string, mixed>>
 */
function wf_demo_data(string $file): array
{
    $data = json_decode((string) file_get_contents(__DIR__ . '/data/' . $file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !array_is_list($data)) {
        throw new RuntimeException("{$file}: erwartet wird eine Liste.");
    }
    $out = [];
    foreach ($data as $entry) {
        if (!is_array($entry)) {
            throw new RuntimeException("{$file}: jeder Eintrag muss ein Objekt sein.");
        }
        $row = [];
        foreach ($entry as $key => $value) {
            $row[(string) $key] = $value;
        }
        $out[] = $row;
    }

    return $out;
}

/** @param array<string, mixed> $row */
function wf_demo_str(array $row, string $key): string
{
    return is_string($row[$key] ?? null) ? $row[$key] : throw new RuntimeException("Angabe '{$key}' fehlt.");
}

$s = wf_demo_services();
$pdo = $s['pdo'];
$statement = $pdo->query('SELECT DATABASE()');
$database = $statement === false ? '' : (string) $statement->fetchColumn();
if (!str_contains($database, 'demo') && getenv('WF_DEMO_SEED_FORCE') !== '1') {
    fwrite(STDERR, "Die Datenbank «{$database}» sieht nicht nach einer Demo aus. WF_DEMO_SEED_FORCE=1 setzt trotzdem." . PHP_EOL);
    exit(2);
}

// 1. Schema und Demo-Tabelle
(new SchemaMigrator($pdo, dirname(__DIR__) . '/schema.sql', dirname(__DIR__) . '/migrations'))->migrate();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['wf_history', 'wf_instance', 'wf_definition', 'wf_template', 'wf_trigger_lock', 'wf_rest_connection', 'wf_rest_model', 'wf_trigger', 'wf_audit'] as $table) {
    $pdo->exec("TRUNCATE TABLE {$table}");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
$pdo->exec('DROP TABLE IF EXISTS demo_antraege');
$pdo->exec('CREATE TABLE demo_antraege (
    id VARCHAR(20) PRIMARY KEY,
    status VARCHAR(40) NOT NULL,
    betrag INT NOT NULL,
    mail VARCHAR(190) NOT NULL,
    geprueft_am DATE NULL,
    archiviert TINYINT NOT NULL DEFAULT 0
)');
$insert = $pdo->prepare('INSERT INTO demo_antraege (id, status, betrag, mail, archiviert) VALUES (?, ?, ?, ?, ?)');
for ($n = 1001; $n <= 1030; $n++) {
    // A-1023 liegt unter 1000 und wartet deshalb auf den Timer; A-1029 ist archiviert.
    $insert->execute(["A-{$n}", 'offen', $n === 1023 ? 800 : 1250, 'person@example.org', $n === 1029 ? 1 : 0]);
}

// 2. Vorlagen
foreach (wf_demo_data('templates.json') as $t) {
    $s['templates']->saveTemplate(new Template(
        wf_demo_str($t, 'id'),
        wf_demo_str($t, 'name'),
        TemplateType::from(wf_demo_str($t, 'type')),
        wf_demo_str($t, 'subject'),
        wf_demo_str($t, 'body'),
    ));
}

// 3. Abläufe: archivierung aktiv, mahnung Entwurf, antrag-pruefung v1–v3
$definitions = [];
foreach (wf_demo_data('definitions.json') as $d) {
    $definitions[wf_demo_str($d, 'id')] = $d;
}
$definition = static fn (string $id): array => $definitions[$id] ?? throw new RuntimeException("Ablauf '{$id}' fehlt in definitions.json.");
/** @param array<string, mixed> $d */
$save = static function (array $d, DefinitionStatus $status) use ($s): int {
    unset($d['draft']);

    return $s['repo']->save(DefinitionParser::fromArray($d), $status)->version;
};
$save($definition('archivierung'), DefinitionStatus::Active);
$save($definition('mahnung'), DefinitionStatus::Draft);

$engine = $s['services']->engine;
$start = static fn (string $subject) => $engine->start('antrag-pruefung', ['antrag_id' => $subject], 'antrag', $subject);
$complete = static function (string $subject) use ($engine, $start): void {
    $instance = $start($subject);
    $engine->handleEvent($instance->id, 'submit', ['bemerkung' => 'In Ordnung', 'geprueft' => true, 'beleg' => "datei-{$subject}.pdf"]);
    $engine->handleEvent($instance->id, 'submit');
};

// v1 mit 4 abgeschlossenen Durchläufen, v2 ohne Durchläufe
$save($definition('antrag-pruefung'), DefinitionStatus::Active);
foreach (['A-1001', 'A-1002', 'A-1003', 'A-1004'] as $subject) {
    $complete($subject);
}
$save($definition('antrag-pruefung'), DefinitionStatus::Active);
// v3 mit 8 Durchläufen: 5 abgeschlossen, 1 abgebrochen, 2 laufend (Ereignis und Timer).
// Zusammen mit v1 sind es 12, wie in der Demo mit Ersatz-Backend.
$save($definition('antrag-pruefung'), DefinitionStatus::Active);
foreach (range(1010, 1014) as $n) {
    $complete("A-{$n}");
}
$engine->cancel($start('A-1021')->id);
$start('A-1023');
$start('A-1024');

// 4. Datenquellen: Verbindung, REST-Modell, Trigger auf dem Datenmodell der Host-App
$actor = new Actor('demo', 'Demo');
$now = new DateTimeImmutable();
$s['store']->saveConnection(new RestConnection(
    'antrag-api',
    'Antrags-API',
    'https://antraege.example.org/api',
    AuthType::Bearer,
    null,
    $s['secrets']->encrypt('demo-token'),
));
$s['store']->saveModel(RestModelSpec::fromArray([
    'name' => 'antrag_api',
    'label' => 'Anträge (API)',
    'connection' => 'antrag-api',
    'idField' => 'id',
    'listPath' => 'data.items',
    'subset' => ['archiviert' => 'false'],
    'operations' => [
        'load' => ['method' => 'GET', 'path' => '/antraege/{id}'],
        'find' => ['method' => 'GET', 'path' => '/antraege'],
    ],
    'fields' => [
        ['name' => 'id', 'label' => 'ID', 'type' => 'text', 'path' => 'data.id'],
        ['name' => 'status', 'label' => 'Status', 'type' => 'text', 'path' => 'data.status'],
        ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'number', 'path' => 'data.betrag'],
        ['name' => 'mail', 'label' => 'E-Mail', 'type' => 'text', 'path' => 'data.kontakt.mail'],
    ],
]));
$s['store']->saveTrigger(AdminTrigger::fromArray([
    'id' => 'offene-antraege',
    'name' => 'Offene Anträge prüfen',
    'model' => 'antrag',
    'conditions' => [['field' => 'status', 'operator' => '=', 'value' => 'offen'], ['field' => 'geprueft_am', 'operator' => 'empty']],
    'definitionId' => 'antrag-pruefung',
    'inputs' => ['antrag_id' => 'id'],
    'intervalMinutes' => 15,
    'lockDays' => 7,
    'active' => false,
]));
foreach ([
    ['connection.created', 'connection', 'antrag-api', ['changed' => ['name', 'baseUrl', 'authType'], 'secretReplaced' => true]],
    ['model.created', 'model', 'antrag_api', []],
    ['trigger.created', 'trigger', 'offene-antraege', []],
] as [$action, $type, $id, $detail]) {
    $s['store']->record(new AuditEntry($now, $actor, $action, $type, $id, $detail));
}

@unlink($s['mailLog']);
echo "Demo-Daten angelegt in «{$database}»." . PHP_EOL;
