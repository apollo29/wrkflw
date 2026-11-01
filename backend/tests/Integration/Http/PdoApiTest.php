<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Http;

use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\ApiServices;
use WorkflowEngine\Http\OpenAccessResolver;
use WorkflowEngine\Model\DataCatalog;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Persistence\PdoTemplateRepository;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Tests\Support\ApiDefinitions;
use WorkflowEngine\Tests\Support\ApiHarness;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

/** Die API auf der echten Ablage: speichern, starten, weiterführen, auflisten. */
final class PdoApiTest extends IntegrationTestCase
{
    public function testFullRoundTrip(): void
    {
        $clock = new FrozenClock();
        $repo = new PdoWorkflowRepository($this->pdo(), $clock);
        $actions = new ActionRegistry();
        $expressions = new SymfonyExpressionEvaluator($clock);
        $app = ApiFactory::create(new ApiServices(
            engine: new WorkflowEngine($repo, $actions, $expressions, $clock),
            workflows: $repo,
            definitions: $repo,
            instances: $repo,
            templates: new PdoTemplateRepository($this->pdo()),
            validator: new DefinitionValidator($expressions),
            actions: $actions,
            catalog: new DataCatalog(new DataModelRegistry([])),
        ), new OpenAccessResolver());

        $send = static function (string $method, string $path, ?array $body = null) use ($app) {
            $request = (new ServerRequestFactory())->createServerRequest($method, $path);
            if ($body !== null) {
                $request = $request->withParsedBody($body);
            }

            return $app->handle($request);
        };

        self::assertSame(201, $send('POST', '/workflows/antrag', ['definition' => ApiDefinitions::antrag()])->getStatusCode());
        $start = $send('POST', '/workflows/antrag/instances', ['context' => ['antragId' => 'A-1']]);
        self::assertSame(201, $start->getStatusCode(), (string) $start->getBody());
        $id = ApiHarness::str($start, 'id');

        $clock->advance('+1 minute');
        self::assertSame('pruefen', ApiHarness::at($send('POST', "/instances/{$id}/events", ['event' => 'weiter']), 'currentStep'));
        self::assertSame('completed', ApiHarness::at($send('POST', "/instances/{$id}/events", ['event' => 'freigeben']), 'status'));

        $list = $send('GET', '/instances?definition=antrag');
        self::assertSame([$id], ApiHarness::column($list, 'instances', 'id'));
        self::assertSame(1, ApiHarness::at($list, 'counts.completed'));
        self::assertSame(1, ApiHarness::at($send('GET', '/workflows'), 'definitions.0.totalInstances'));
        self::assertSame(['start', 'event', 'event', 'complete'], array_values(array_filter(
            ApiHarness::column($send('GET', "/instances/{$id}/history"), 'history', 'kind'),
            static fn (mixed $kind): bool => in_array($kind, ['start', 'event', 'complete'], true),
        )));
    }
}
