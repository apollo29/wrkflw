<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\WorkflowController;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * `POST /instances/{id}/back` und das `canGoBack` in `current-step`.
 *
 * Die Engine-Logik selbst steht in `Unit\Engine\GoBackTest`; hier geht es nur
 * um die HTTP-Schicht: kommt der Endpunkt an, gibt er die richtigen Codes, und
 * erfaehrt eine Oberflaeche vorher, ob der Knopf ueberhaupt etwas bewirkt.
 *
 * Der letzte Punkt ist der wichtigste: ein Knopf, den der Server anschliessend
 * mit 409 abweist, ist schlimmer als gar keiner.
 */
#[CoversClass(ApiFactory::class)]
#[CoversClass(WorkflowController::class)]
final class BackApiTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private WorkflowEngine $engine;
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'erste',
            'steps' => [
                'erste' => [
                    'type' => 'interactive',
                    'transitions' => [['to' => 'zweite', 'event' => 'submit']],
                ],
                'zweite' => [
                    'type' => 'interactive',
                    'ui' => ['back' => true],
                    'transitions' => [['to' => 'fertig', 'event' => 'submit']],
                ],
                'fertig' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));

        $this->engine = new WorkflowEngine($this->repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
        $this->app = ApiFactory::create($this->engine, $this->repo);
    }

    public function testBackReturnsTheInstanceOnItsPreviousStep(): void
    {
        $id = $this->aufDemZweitenSchritt();

        $response = $this->send('POST', "/instances/{$id}/back");

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame($id, $data['id']);
        self::assertSame('erste', $data['currentStep']);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $data['status']);
    }

    /** Gegenprobe: vom ersten Schritt aus gibt es nichts, wohin. */
    public function testBackFromTheFirstStepReturns409(): void
    {
        $instance = $this->engine->start('flow');

        $response = $this->send('POST', "/instances/{$instance->id}/back");

        self::assertSame(409, $response->getStatusCode());
        $error = $this->decode($response)['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('conflict', $error['code']);
    }

    public function testBackOnAnUnknownInstanceReturns404(): void
    {
        $response = $this->send('POST', '/instances/gibtsnicht/back');

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * `current-step` sagt vorher, ob der Knopf etwas bewirkt — auf dem ersten
     * Schritt nicht, auf dem zweiten schon.
     */
    public function testCurrentStepReportsWhetherBackIsPossible(): void
    {
        $instance = $this->engine->start('flow');
        $erste = $this->decode($this->send('GET', "/instances/{$instance->id}/current-step"));
        self::assertFalse($erste['canGoBack']);

        $this->engine->handleEvent($instance->id, 'submit');
        $zweite = $this->decode($this->send('GET', "/instances/{$instance->id}/current-step"));
        self::assertTrue($zweite['canGoBack']);
    }

    private function aufDemZweitenSchritt(): string
    {
        $instance = $this->engine->start('flow');
        $this->engine->handleEvent($instance->id, 'submit');
        self::assertSame('zweite', $instance->currentStep);

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
}
