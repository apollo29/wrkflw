<?php

declare(strict_types=1);

/**
 * Beispielhaftes Wiring der Engine in eine Host-App ueber einen PSR-11-Container
 * (php-di). Hier implementiert die Host-App die PORTS (Interfaces) und verdrahtet
 * alles. buildContainer() liefert den Container; buildEngine() ist ein Komfort-Helfer
 * fuer die Cron-/CLI-Skripte.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Contracts\DataProviderInterface;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Contracts\MailerInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Http\WorkflowController;
use WorkflowEngine\Persistence\PdoWorkflowRepository;

/* ---- 1) Adapter der Host-App: Mailer ------------------------------------ */
final class AppMailer implements MailerInterface
{
    /** @param string $defaultFrom Standard-Mailbox, wenn die Nachricht kein From setzt. */
    public function __construct(private readonly string $defaultFrom = 'no-reply@example.com')
    {
    }

    public function send(\WorkflowEngine\Contracts\EmailMessage $message): void
    {
        $from = $message->from !== '' ? $message->from : $this->defaultFrom;
        // Hier echten Versand anbinden (Symfony Mailer, PHPMailer, Queue ...).
        $cc = $message->cc === [] ? '' : ' cc=' . implode(',', $message->cc);
        error_log("[MAIL] {$from} -> {$message->to}{$cc} | {$message->subject}");
    }
}

/* ---- 2) Adapter der Host-App: Datenzugriff ------------------------------ */
final class AppDataProvider implements DataProviderInterface, \WorkflowEngine\Contracts\DataCatalogInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function entities(): array
    {
        // Katalog fuer den Datencheck-Schritt (dieselbe Whitelist wie table()).
        return [
            ['entity' => 'order', 'label' => 'Bestellung', 'fields' => ['id', 'status', 'total']],
            ['entity' => 'user', 'label' => 'Benutzer', 'fields' => ['id', 'name', 'email', 'vip']],
            ['entity' => 'invoice', 'label' => 'Rechnung', 'fields' => ['id', 'paid', 'amount']],
        ];
    }

    public function get(string $entity, string|int $id): ?array
    {
        $table = $this->table($entity);
        if ($table === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function find(string $entity, array $criteria): array
    {
        $table = $this->table($entity);
        if ($table === null) {
            return [];
        }

        $sql = "SELECT * FROM {$table}";
        $params = [];
        $clauses = [];
        $i = 0;
        foreach ($criteria as $column => $value) {
            // Nur einfache, sichere Spaltennamen zulassen (Whitelist per Muster).
            if (!is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                continue;
            }
            $placeholder = ":p{$i}";
            $clauses[] = "{$column} = {$placeholder}";
            $params[$placeholder] = $value;
            $i++;
        }
        if ($clauses !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, 'is_array'));
    }

    /** Whitelist erlaubter Entitaeten -> Tabellen. */
    private function table(string $entity): ?string
    {
        return match ($entity) {
            'order' => 'orders',
            'user' => 'users',
            'invoice' => 'invoices',
            default => null,
        };
    }
}

/* ---- 3) PSR-11-Container aufbauen --------------------------------------- */
function buildContainer(\PDO $pdo): ContainerInterface
{
    $builder = new ContainerBuilder();
    $builder->addDefinitions([
        \PDO::class => $pdo,
        MailerInterface::class => \DI\create(AppMailer::class),
        DataProviderInterface::class => \DI\autowire(AppDataProvider::class),
        ExpressionEvaluatorInterface::class => \DI\create(SymfonyExpressionEvaluator::class),
        WorkflowRepositoryInterface::class => \DI\autowire(PdoWorkflowRepository::class),
        \WorkflowEngine\Contracts\TemplateRepositoryInterface::class =>
            \DI\autowire(\WorkflowEngine\Persistence\PdoTemplateRepository::class),
        ActionRegistry::class => function (ContainerInterface $c): ActionRegistry {
            $registry = new ActionRegistry();
            $registry->register('send_email', new SendEmailAction(
                $c->get(MailerInterface::class),
                $c->get(\WorkflowEngine\Contracts\TemplateRepositoryInterface::class),
            ));
            // Verknuepfte Workflows: die Action startet weitere Workflows ueber die Engine.
            // Lazy aufgeloest, um den Zirkelbezug Action -> Engine -> Registry zu brechen.
            $registry->register('start_workflow', new \WorkflowEngine\Action\SubWorkflowAction(
                static fn (): \WorkflowEngine\Contracts\WorkflowStarterInterface => $c->get(WorkflowEngine::class),
            ));
            // Datencheck: liest einen Wert aus einer Host-Tabelle in den Kontext.
            $registry->register('check_data', new \WorkflowEngine\Action\CheckDataAction(
                $c->get(DataProviderInterface::class),
            ));
            // Eigene Aktionen der Host-App hier zusaetzlich registrieren.
            return $registry;
        },
        \WorkflowEngine\Contracts\DataCatalogInterface::class =>
            static fn (ContainerInterface $c): \WorkflowEngine\Contracts\DataCatalogInterface
                => $c->get(DataProviderInterface::class),
        WorkflowEngine::class => \DI\autowire()->constructor(
            \DI\get(WorkflowRepositoryInterface::class),
            \DI\get(ActionRegistry::class),
            \DI\get(ExpressionEvaluatorInterface::class),
        ),
        WorkflowRunner::class => \DI\autowire()->constructor(
            \DI\get(WorkflowEngine::class),
            \DI\get(WorkflowRepositoryInterface::class),
        ),
        WorkflowController::class => \DI\autowire(),
        WorkflowEngine\Http\DefinitionController::class => \DI\autowire(),
        WorkflowEngine\Http\ActionController::class => \DI\autowire(),
        WorkflowEngine\Http\TemplateController::class => \DI\autowire(),
        WorkflowEngine\Http\DataCatalogController::class => \DI\autowire(),
    ]);

    return $builder->build();
}

/**
 * Komfort-Helfer fuer Cron/CLI: liefert [Engine, Runner, Repository].
 *
 * @return array{0:WorkflowEngine,1:WorkflowRunner,2:WorkflowRepositoryInterface}
 */
function buildEngine(\PDO $pdo): array
{
    $container = buildContainer($pdo);

    return [
        $container->get(WorkflowEngine::class),
        $container->get(WorkflowRunner::class),
        $container->get(WorkflowRepositoryInterface::class),
    ];
}
