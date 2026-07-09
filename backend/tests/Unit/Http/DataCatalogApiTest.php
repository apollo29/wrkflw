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
use WorkflowEngine\Http\DataCatalogController;
use WorkflowEngine\Tests\Support\InMemoryDataProvider;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

#[CoversClass(DataCatalogController::class)]
final class DataCatalogApiTest extends TestCase
{
    public function testListReturnsEntities(): void
    {
        $repo = new InMemoryWorkflowRepository();
        $engine = new WorkflowEngine($repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
        $catalog = new InMemoryDataProvider();
        $catalog->setCatalog([
            ['entity' => 'order', 'label' => 'Bestellung', 'fields' => ['id', 'status']],
        ]);

        $app = ApiFactory::create($engine, $repo, null, null, null, $catalog);

        $response = $this->handle($app, 'GET', '/data-catalog');
        self::assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $entities = $data['entities'] ?? null;
        self::assertIsArray($entities);
        self::assertCount(1, $entities);
        self::assertIsArray($entities[0]);
        self::assertSame('order', $entities[0]['entity'] ?? null);
        self::assertSame(['id', 'status'], $entities[0]['fields'] ?? null);
    }

    /**
     * @param App<\Psr\Container\ContainerInterface|null> $app
     */
    private function handle(App $app, string $method, string $path): ResponseInterface
    {
        return $app->handle((new ServerRequestFactory())->createServerRequest($method, $path));
    }
}
