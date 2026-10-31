<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestPersistence;
use WorkflowEngine\Security\SafeHttpClient;
use WorkflowEngine\Security\UrlGuard;
use WorkflowEngine\Tests\Support\EngineHarness;
use WorkflowEngine\Tests\Support\FakeResolver;
use WorkflowEngine\Tests\Support\InMemoryDataSourceStore;
use WorkflowEngine\Tests\Support\RestFixtures;
use WorkflowEngine\Tests\Unit\Definition\DefinitionParserTest;

/**
 * Der Beispielablauf 9.13 über das REST-Modell `antrag` aus 5.6. Für den Ablauf ist es
 * dasselbe wie die Variante auf einer Tabelle; die Definition bleibt unverändert.
 */
final class RestEndToEndTest extends TestCase
{
    public function testTheExampleFlowRunsAgainstARestApi(): void
    {
        $requests = [];
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url . (is_string($options['body'] ?? null) && $options['body'] !== '' ? ' ' . $options['body'] : '');

            return $method === 'GET'
                ? new MockResponse((string) json_encode(RestFixtures::antragJson()), ['http_code' => 200])
                : new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $store = new InMemoryDataSourceStore();
        $store->saveConnection(RestFixtures::connection());
        $store->saveModel(RestFixtures::model());
        $http = new SafeHttpClient($mock, new UrlGuard(new FakeResolver(['api.example.org' => ['93.184.216.34']])));

        $h = new EngineHarness();
        $h->addModel(RestFixtures::model()->toDataModel(true));
        $gateway = new DataGateway(new DataModelRegistry([$h->models]), new PersistenceRegistry([
            'rest' => new RestPersistence($http, $store, $store, RestFixtures::secrets(), new RestOptions()),
        ]));
        $h->actions->register('check_data', new \WorkflowEngine\Action\CheckDataAction($gateway));
        $h->actions->register('write_data', new \WorkflowEngine\Action\WriteDataAction($gateway, new \WorkflowEngine\Engine\PlaceholderRenderer($h->clock), $h->expressions));
        $h->templates->saveTemplate(new Template('antrag-ok', 'Antrag bestätigt', TemplateType::Email, 'Antrag {{antrag_id}}', 'Betrag {{antrag_betrag}}'));
        $h->defineJson(DefinitionParserTest::fixture());
        $h->define(['id' => 'archivierung', 'startStep' => 'a', 'steps' => ['a' => []]]);

        $instance = $h->engine->start('antrag-pruefung', ['antrag_id' => 'A-1024']);
        self::assertSame('pruefen', $instance->currentStep);
        self::assertSame('person@example.org', $instance->context['antrag_mail']);

        $done = $h->engine->handleEvent($instance->id, 'submit', ['bemerkung' => 'ok']);

        self::assertSame(InstanceStatus::Completed, $done->status);
        self::assertSame([
            'GET https://api.example.org/v1/antraege/A-1024?archiviert=false',
            'GET https://api.example.org/v1/antraege/A-1024?archiviert=false',
            'PATCH https://api.example.org/v1/antraege/A-1024?archiviert=false {"status":"freigegeben","geprueft_am":"2026-10-31"}',
        ], $requests);
        self::assertSame('person@example.org', $h->mailer->last()->to);
    }
}
