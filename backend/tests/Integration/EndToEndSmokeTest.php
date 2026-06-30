<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Tests\Support\ArrayMailer;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

/**
 * End-to-End-Smoke-Test: API + MariaDB + Engine + Runner + Mailer im Zusammenspiel,
 * gegen die echte Datenbank und mit dem Onboarding-Beispiel.
 */
#[Group('integration')]
final class EndToEndSmokeTest extends IntegrationTestCase
{
    private PdoWorkflowRepository $repo;
    private WorkflowRunner $runner;
    private ArrayMailer $mailer;
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedOnboarding();

        $this->repo = new PdoWorkflowRepository($this->pdo());
        $this->mailer = new ArrayMailer();
        $actions = new ActionRegistry();
        $actions->register('send_email', new SendEmailAction($this->mailer));
        $engine = new WorkflowEngine($this->repo, $actions, new SymfonyExpressionEvaluator());
        $this->runner = new WorkflowRunner($engine, $this->repo);
        $this->app = ApiFactory::create($engine, $this->repo);
    }

    public function testEnterprisePathReachesCompletedViaApi(): void
    {
        $start = $this->send('POST', '/workflows/onboarding/instances', [
            'context' => ['name' => 'Mara', 'email' => 'mara@example.com', 'plan' => 'enterprise'],
        ]);
        self::assertSame(201, $start->getStatusCode());
        $id = $this->decode($start)['id'];
        self::assertIsString($id);

        $event = $this->send('POST', "/instances/{$id}/events", [
            'event' => 'submit',
            'payload' => ['acceptedTerms' => true],
        ]);
        self::assertSame(200, $event->getStatusCode());
        self::assertSame('completed', $this->decode($event)['status']);

        // Willkommens- und VIP-Mail wurden versendet.
        self::assertCount(2, $this->mailer->messages());

        $history = $this->send('GET', "/instances/{$id}/history");
        self::assertSame(200, $history->getStatusCode());
        $entries = $this->decode($history)['history'];
        self::assertIsArray($entries);
        $kinds = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $kinds[] = $entry['kind'] ?? null;
            }
        }
        self::assertContains('start', $kinds);
        self::assertContains('complete', $kinds);
    }

    public function testStandardPathCompletesViaRunnerAfterTimer(): void
    {
        $start = $this->send('POST', '/workflows/onboarding/instances', [
            'context' => ['name' => 'Lea', 'email' => 'lea@example.com', 'plan' => 'pro'],
        ]);
        $id = $this->decode($start)['id'];
        self::assertIsString($id);

        $event = $this->send('POST', "/instances/{$id}/events", [
            'event' => 'submit',
            'payload' => ['acceptedTerms' => true],
        ]);
        self::assertSame('waiting_timer', $this->decode($event)['status']);

        // Timer faellig machen und den Runner laufen lassen.
        $this->pdo()
            ->prepare('UPDATE wf_instance SET wake_at = :w WHERE id = :id')
            ->execute([':w' => '2000-01-01 00:00:00', ':id' => $id]);

        $stats = $this->runner->tick();
        self::assertSame(1, $stats['woken']);

        $instance = $this->repo->findInstance($id);
        self::assertNotNull($instance);
        self::assertSame(WorkflowInstance::COMPLETED, $instance->status);

        // Willkommens- und Tipps-Mail wurden versendet.
        self::assertCount(2, $this->mailer->messages());
    }

    // ---------------------------------------------------------------- Helpers

    private function seedOnboarding(): void
    {
        $json = file_get_contents(dirname(__DIR__, 2) . '/examples/onboarding.json');
        self::assertIsString($json);

        $this->pdo()
            ->prepare(
                'INSERT INTO wf_definition (id, version, name, definition, active)
                 VALUES (:id, 1, :name, :def, 1)'
            )
            ->execute([':id' => 'onboarding', ':name' => 'Onboarding', ':def' => $json]);
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
