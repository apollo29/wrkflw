<?php

declare(strict_types=1);

/*
 * Demo-Host-App: verdrahtet die Engine so, wie es eine echte Anwendung täte, aber nur mit
 * Bordmitteln der Library. Wird von `demo/api.php` (HTTP) und `demo/seed.php` benutzt.
 *
 * Umgebung:
 *   WF_DB_DSN, WF_DB_USER, WF_DB_PASS   Datenbank (Standard: lokale MariaDB, `workflow_demo`)
 *   WF_SECRET_KEY                       Schlüssel für Zugangsdaten (Demo-Standard, wenn leer)
 *   WF_DEMO_MAIL_LOG                    Datei, in die «gesendete» Mails geschrieben werden
 *
 * Nicht Teil der Library: die Engine kennt keine Host-App (ADR 1).
 */

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\CheckDataAction;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Action\SubWorkflowAction;
use WorkflowEngine\Action\WriteDataAction;
use WorkflowEngine\Contracts\EmailMessage;
use WorkflowEngine\Contracts\MailerInterface;
use WorkflowEngine\Contracts\UploadHandler;
use WorkflowEngine\Contracts\UploadHandlerCatalogInterface;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\KnownActionRule;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\SystemClock;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Http\ApiServices;
use WorkflowEngine\Http\DataSources;
use WorkflowEngine\Model\DataCatalog;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\DataModelStepRule;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Persistence\PdoDataSourceStore;
use WorkflowEngine\Persistence\PdoTablePersistence;
use WorkflowEngine\Persistence\PdoTemplateRepository;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Rest\DataSourceAdmin;
use WorkflowEngine\Rest\RestDataModelSource;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestPersistence;
use WorkflowEngine\Security\SafeHttpClient;
use WorkflowEngine\Security\SodiumSecretStore;
use WorkflowEngine\Security\UrlGuard;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/** Demo-Mailer: hängt jede Mail als JSON-Zeile an eine Datei an. */
final class DemoFileMailer implements MailerInterface
{
    public function __construct(private readonly string $file)
    {
    }

    public function send(EmailMessage $message): void
    {
        $line = json_encode([
            'at' => date(DATE_ATOM),
            'to' => $message->to,
            'subject' => $message->subject,
            'body' => $message->body,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($this->file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

/**
 * @return array{pdo: PDO, services: ApiServices, repo: PdoWorkflowRepository, templates: PdoTemplateRepository, store: PdoDataSourceStore, secrets: SodiumSecretStore, mailLog: string}
 */
function wf_demo_services(): array
{
    $env = static fn (string $name, string $default = ''): string => is_string($v = getenv($name)) && $v !== '' ? $v : $default;

    $pdo = new PDO(
        $env('WF_DB_DSN', 'mysql:host=127.0.0.1;port=3306;dbname=workflow_demo;charset=utf8mb4'),
        $env('WF_DB_USER', 'root'),
        $env('WF_DB_PASS', 'root'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    // Nur für die Demo: ein fester Schlüssel, damit sie ohne Einrichtung läuft.
    $key = $env('WF_SECRET_KEY', base64_encode(str_repeat('d', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $secrets = new SodiumSecretStore($key);
    $mailLog = $env('WF_DEMO_MAIL_LOG', sys_get_temp_dir() . '/wrkflw-demo-mails.jsonl');

    $clock = new SystemClock();
    $repo = new PdoWorkflowRepository($pdo, $clock);
    $templates = new PdoTemplateRepository($pdo);
    $store = new PdoDataSourceStore($pdo, $clock);
    $expressions = new SymfonyExpressionEvaluator($clock);
    $renderer = new PlaceholderRenderer($clock);
    $options = new RestOptions();
    $http = new SafeHttpClient(null, new UrlGuard(), $options);

    // Das Datenmodell der Host-App: Anträge in der Demo-Tabelle `demo_antraege`.
    $antrag = DataModel::define('antrag', 'Anträge')
        ->source('demo_antraege')
        ->text('status', 'Status')->writable()
        ->number('betrag', 'Betrag')
        ->text('mail', 'E-Mail')
        ->date('geprueft_am', 'Geprüft am')->writable()
        ->subset(['archiviert' => 0])
        ->updatable()
        ->build();
    $registry = new DataModelRegistry([new StaticDataModelSource([$antrag]), new RestDataModelSource($store, $options)]);
    $gateway = new DataGateway($registry, new PersistenceRegistry([
        'db' => new PdoTablePersistence($pdo),
        'rest' => new RestPersistence($http, $store, $store, $secrets, $options),
    ]));

    $actions = new ActionRegistry();
    $engine = new WorkflowEngine($repo, $actions, $expressions, $clock);
    $actions->register('send_email', new SendEmailAction(new DemoFileMailer($mailLog), $templates, $renderer));
    $actions->register('start_workflow', new SubWorkflowAction(static fn (): WorkflowEngine => $engine));
    $actions->register('check_data', new CheckDataAction($gateway, $renderer));
    $actions->register('write_data', new WriteDataAction($gateway, $renderer, $expressions));

    $admin = new DataSourceAdmin($store, $store, $store, $store, $secrets, new UrlGuard(), $http, $registry, $gateway, $repo, $repo, $options, $clock);

    $services = new ApiServices(
        engine: $engine,
        workflows: $repo,
        definitions: $repo,
        instances: $repo,
        templates: $templates,
        validator: new DefinitionValidator($expressions, [new KnownActionRule($actions), new DataModelStepRule($registry)]),
        actions: $actions,
        catalog: new DataCatalog($registry),
        uploadHandlers: new class () implements UploadHandlerCatalogInterface {
            public function handlers(): array
            {
                return [new UploadHandler('pdf_max_5mb', 'PDF bis 5 MB', 'Nur PDF-Dateien bis 5 MB.')];
            }
        },
        dataSources: new DataSources($admin, $store, $store, $store, $store),
    );

    return ['pdo' => $pdo, 'services' => $services, 'repo' => $repo, 'templates' => $templates, 'store' => $store, 'secrets' => $secrets, 'mailLog' => $mailLog];
}
