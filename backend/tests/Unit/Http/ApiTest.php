<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\Middleware\ApiKeyAuthMiddleware;
use WorkflowEngine\Http\WorkflowController;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

#[CoversClass(ApiFactory::class)]
#[CoversClass(WorkflowController::class)]
#[CoversClass(ApiKeyAuthMiddleware::class)]
final class ApiTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private WorkflowEngine $engine;
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->onboarding()));

        $actions = new ActionRegistry();
        $actions->register('send_email', new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                return [];
            }
        });
        $this->engine = new WorkflowEngine($this->repo, $actions, new SymfonyExpressionEvaluator());
        $this->app = ApiFactory::create($this->engine, $this->repo);
    }

    public function testStartReturns201WithInstanceState(): void
    {
        $response = $this->send('POST', '/workflows/onboarding/instances', [
            'context' => ['plan' => 'enterprise', 'email' => 'e@x.de'],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertIsString($data['id']);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $data['status']);
        self::assertSame('await_profile', $data['currentStep']);
    }

    public function testStartUnknownDefinitionReturns404(): void
    {
        $response = $this->send('POST', '/workflows/ghost/instances', ['context' => []]);

        self::assertSame(404, $response->getStatusCode());
        $data = $this->decode($response);
        $error = $data['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('not_found', $error['code']);
    }

    public function testShowReturnsInstanceState(): void
    {
        $id = $this->startInstance(['plan' => 'pro', 'email' => 'p@x.de']);

        $response = $this->send('GET', "/instances/{$id}");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame($id, $data['id']);
        self::assertSame('await_profile', $data['currentStep']);
        self::assertIsArray($data['context']);
    }

    public function testShowUnknownInstanceReturns404(): void
    {
        $response = $this->send('GET', '/instances/nope');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCurrentStepReturnsUiAndEvents(): void
    {
        $id = $this->startInstance(['plan' => 'pro', 'email' => 'p@x.de']);

        $response = $this->send('GET', "/instances/{$id}/current-step");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertTrue($data['interactive']);
        $events = $data['events'] ?? null;
        self::assertIsArray($events);
        self::assertContains('submit', $events);
        $ui = $data['ui'] ?? null;
        self::assertIsArray($ui);
        self::assertSame('Profil vervollstaendigen', $ui['title']);
    }

    public function testPostEventAdvancesInstance(): void
    {
        $id = $this->startInstance(['plan' => 'pro', 'email' => 'p@x.de']);

        $response = $this->send('POST', "/instances/{$id}/events", [
            'event' => 'submit',
            'payload' => ['acceptedTerms' => true],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertNotSame('await_profile', $data['currentStep']);
    }

    public function testPostEventMissingEventReturns422(): void
    {
        $id = $this->startInstance(['plan' => 'pro', 'email' => 'p@x.de']);

        $response = $this->send('POST', "/instances/{$id}/events", ['payload' => []]);

        self::assertSame(422, $response->getStatusCode());
        $data = $this->decode($response);
        $error = $data['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('validation_error', $error['code']);
    }

    public function testPostEventUnknownInstanceReturns404(): void
    {
        $response = $this->send('POST', '/instances/nope/events', ['event' => 'submit']);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testPostEventOnFinishedInstanceReturns409(): void
    {
        $id = $this->startInstance(['plan' => 'enterprise', 'email' => 'e@x.de']);
        $this->send('POST', "/instances/{$id}/events", ['event' => 'submit', 'payload' => ['acceptedTerms' => true]]);

        $response = $this->send('POST', "/instances/{$id}/events", ['event' => 'submit']);

        self::assertSame(409, $response->getStatusCode());
        $data = $this->decode($response);
        $error = $data['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('conflict', $error['code']);
    }

    public function testHistoryEndpointReturnsEntries(): void
    {
        $id = $this->startInstance(['plan' => 'pro', 'email' => 'p@x.de']);
        $this->send('POST', "/instances/{$id}/events", ['event' => 'submit', 'payload' => ['acceptedTerms' => true]]);

        $response = $this->send('GET', "/instances/{$id}/history");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $history = $data['history'] ?? null;
        self::assertIsArray($history);
        self::assertNotEmpty($history);
        $kinds = [];
        foreach ($history as $entry) {
            if (is_array($entry)) {
                $kinds[] = $entry['kind'] ?? null;
            }
        }
        self::assertContains('start', $kinds);
        self::assertContains('transition', $kinds);
    }

    public function testAuthMiddlewareRejectsMissingKey(): void
    {
        $app = ApiFactory::create($this->engine, $this->repo, new ApiKeyAuthMiddleware('secret'));

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/instances/whatever');
        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAuthMiddlewareAcceptsValidKey(): void
    {
        $app = ApiFactory::create($this->engine, $this->repo, new ApiKeyAuthMiddleware('secret'));

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/instances/nope')
            ->withHeader('Authorization', 'Bearer secret');
        $response = $app->handle($request);

        // Key akzeptiert -> Route laeuft -> 404 (Instanz unbekannt), nicht 401.
        self::assertSame(404, $response->getStatusCode());
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * @param array<string,mixed> $context
     */
    private function startInstance(array $context): string
    {
        $instance = $this->engine->start('onboarding', $context);

        return $instance->id;
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

    /**
     * @return array<string,mixed>
     */
    private function onboarding(): array
    {
        $json = file_get_contents(dirname(__DIR__, 3) . '/examples/onboarding.json');
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
