<?php

declare(strict_types=1);

/**
 * Beispielhaftes Wiring der Engine in eine Host-App.
 * Hier implementiert die Host-App die PORTS (Interfaces) und verdrahtet alles.
 * In einer echten App gehoert das in den DI-Container.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Contracts\DataProviderInterface;
use WorkflowEngine\Contracts\MailerInterface;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Persistence\PdoWorkflowRepository;

/* ---- 1) Adapter der Host-App: Mailer ------------------------------------ */
final class AppMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $body, array $vars = []): void
    {
        // Hier echten Versand anbinden (Symfony Mailer, PHPMailer, Queue ...).
        error_log("[MAIL] -> {$to} | {$subject}");
    }
}

/* ---- 2) Adapter der Host-App: Datenzugriff ------------------------------ */
final class AppDataProvider implements DataProviderInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function get(string $entity, string|int $id): ?array
    {
        // Nur erlaubte Entitaeten zulassen (Whitelist!).
        $table = match ($entity) {
            'order' => 'orders',
            'user' => 'users',
            default => null,
        };
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
        // Vereinfachtes Beispiel; in Produktion mit sauberem Query-Builder.
        return [];
    }
}

/* ---- 3) Engine zusammensetzen ------------------------------------------- */
/**
 * @return array{0:WorkflowEngine,1:WorkflowRunner,2:PdoWorkflowRepository}
 */
function buildEngine(\PDO $pdo): array
{
    $repo = new PdoWorkflowRepository($pdo);
    $evaluator = new SymfonyExpressionEvaluator();

    $actions = new ActionRegistry();
    $actions->register('send_email', new SendEmailAction(new AppMailer()));
    // Eigene Aktionen der Host-App hier zusaetzlich registrieren:
    // $actions->register('charge_card', new ChargeCardAction(...));

    $engine = new WorkflowEngine($repo, $actions, $evaluator);
    $runner = new WorkflowRunner($engine, $repo);

    return [$engine, $runner, $repo];
}
