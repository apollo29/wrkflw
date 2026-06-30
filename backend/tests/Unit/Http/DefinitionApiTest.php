<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\DefinitionController;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

#[CoversClass(DefinitionController::class)]
final class DefinitionApiTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $engine = new WorkflowEngine($this->repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
        $this->app = ApiFactory::create($engine, $this->repo);
    }

    /** @var array<string,mixed> */
    private const VALID_DEFINITION = [
        'startStep' => 'a',
        'steps' => [
            'a' => ['type' => 'interactive', 'transitions' => [['event' => 'go', 'to' => 'b']]],
            'b' => ['type' => 'automatic'],
        ],
    ];

    public function testSaveListGetRoundtrip(): void
    {
        $create = $this->send('POST', '/workflows/myflow', [
            'name' => 'My Flow',
            'definition' => self::VALID_DEFINITION,
        ]);
        self::assertSame(201, $create->getStatusCode());
        $created = $this->decode($create);
        self::assertSame('myflow', $created['id']);
        self::assertSame(1, $created['version']);

        $list = $this->decode($this->send('GET', '/workflows'));
        $definitions = $list['definitions'] ?? null;
        self::assertIsArray($definitions);
        self::assertCount(1, $definitions);

        $get = $this->send('GET', '/workflows/myflow');
        self::assertSame(200, $get->getStatusCode());
        $definition = $this->decode($get)['definition'] ?? null;
        self::assertIsArray($definition);
        self::assertSame('a', $definition['startStep']);
        self::assertSame('myflow', $definition['id']);
    }

    public function testGetUnknownDefinitionReturns404(): void
    {
        self::assertSame(404, $this->send('GET', '/workflows/ghost')->getStatusCode());
    }

    public function testSaveWithoutDefinitionReturns422(): void
    {
        $response = $this->send('POST', '/workflows/x', ['name' => 'X']);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->decode($response)['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('validation_error', $error['code']);
    }

    public function testSaveInvalidDefinitionReturns400(): void
    {
        // Transition verweist auf unbekanntes Ziel -> DefinitionValidator schlaegt an.
        $response = $this->send('POST', '/workflows/broken', [
            'definition' => [
                'startStep' => 'a',
                'steps' => ['a' => ['type' => 'automatic', 'transitions' => [['to' => 'ghost']]]],
            ],
        ]);

        self::assertSame(400, $response->getStatusCode());
        $error = $this->decode($response)['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('invalid_definition', $error['code']);
    }

    public function testSaveCreatesUsableDefinitionForStart(): void
    {
        $this->send('POST', '/workflows/myflow', ['definition' => self::VALID_DEFINITION]);

        $start = $this->send('POST', '/workflows/myflow/instances', ['context' => []]);
        self::assertSame(201, $start->getStatusCode());
        self::assertSame('waiting_event', $this->decode($start)['status']);
    }

    // ---------------------------------------------------------------- Helpers

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
