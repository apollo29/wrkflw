<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\Response\MockResponse;
use WorkflowEngine\Tests\Support\ApiDefinitions;
use WorkflowEngine\Tests\Support\ApiHarness;

/** Reiter «Datenquellen» über die API (9.16, F-133 bis F-145). */
final class DataSourceApiTest extends TestCase
{
    private const string SECRET = 'geheimes-token-42';

    private ApiHarness $api;

    protected function setUp(): void
    {
        $this->api = new ApiHarness();
    }

    private function createConnection(): ResponseInterface
    {
        return $this->api->send('POST', '/data-sources/connections', [
            'id' => 'antrag-api',
            'name' => 'Antrags-API',
            'baseUrl' => 'https://api.example.org/v1',
            'authType' => 'bearer',
            'secret' => self::SECRET,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function modelSpec(string $name = 'gesuch'): array
    {
        return [
            'name' => $name,
            'label' => 'Gesuche',
            'connection' => 'antrag-api',
            'listPath' => 'data.items',
            'operations' => ['load' => ['path' => '/antraege/{id}'], 'find' => ['path' => '/antraege']],
            'fields' => [
                ['name' => 'id', 'label' => 'ID'],
                ['name' => 'status', 'label' => 'Status'],
                ['name' => 'mail', 'label' => 'E-Mail', 'path' => 'kontakt.mail'],
            ],
        ];
    }

    private function respond(int $status, mixed $json): void
    {
        $this->api->responses[] = new MockResponse(json_encode($json, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    public function testConnectionsNeverShowTheSecret(): void
    {
        $created = $this->createConnection();
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertTrue(ApiHarness::at($created, 'secretStored'));
        self::assertSame(409, $this->createConnection()->getStatusCode(), 'ID schon vergeben');

        $updated = $this->api->send('PUT', '/data-sources/connections/antrag-api', ['name' => 'Neu', 'baseUrl' => 'https://api.example.org/v2', 'authType' => 'bearer']);
        self::assertSame(200, $updated->getStatusCode());
        self::assertTrue(ApiHarness::at($updated, 'secretStored'), 'ohne neues Geheimnis bleibt das alte');
        self::assertSame(200, $this->api->send('PUT', '/data-sources/connections/antrag-api/secret', ['secret' => 'neu-99'])->getStatusCode());
        self::assertSame(422, $this->api->send('PUT', '/data-sources/connections/antrag-api/secret', [])->getStatusCode());

        $bodies = [
            $created, $updated,
            $this->api->send('GET', '/data-sources/connections'),
            $this->api->send('GET', '/data-sources/connections/antrag-api'),
            $this->api->send('GET', '/data-sources/audit'),
        ];
        foreach ($bodies as $response) {
            self::assertStringNotContainsString(self::SECRET, (string) $response->getBody());
            self::assertStringNotContainsString('neu-99', (string) $response->getBody());
            self::assertStringNotContainsString('v1:', (string) $response->getBody(), 'auch nicht verschlüsselt');
        }

        $audit = $this->api->send('GET', '/data-sources/audit?type=connection');
        self::assertSame(['connection.secret_replaced', 'connection.updated', 'connection.created'], ApiHarness::column($audit, 'entries', 'action'));
        self::assertSame('Admin Muster', ApiHarness::at($audit, 'entries.0.actor.name'));
    }

    public function testConnectionValidationAndInternalAddresses(): void
    {
        $internal = $this->api->send('POST', '/data-sources/connections', ['id' => 'intern', 'name' => 'Intern', 'baseUrl' => 'https://intern.example.org']);
        self::assertSame(422, $internal->getStatusCode());
        self::assertSame('validation_error', ApiHarness::at($internal, 'error.code'));
        self::assertArrayHasKey('baseUrl', (array) ApiHarness::at($internal, 'error.details.fields'));
        self::assertSame(422, $this->api->send('POST', '/data-sources/connections', ['id' => 'x', 'name' => 'x', 'baseUrl' => 'https://api.example.org', 'authType' => 'oauth'])->getStatusCode());
        self::assertSame(404, $this->api->send('PUT', '/data-sources/connections/fehlt', ['name' => 'x'])->getStatusCode());
    }

    public function testConnectionTestReportsOnlyStatusAndDuration(): void
    {
        $this->createConnection();
        $this->respond(200, ['personen' => ['Anna']]);

        $test = $this->api->send('POST', '/data-sources/connections/antrag-api/test');
        self::assertSame(200, $test->getStatusCode());
        self::assertTrue(ApiHarness::at($test, 'ok'));
        self::assertSame(200, ApiHarness::at($test, 'status'));
        self::assertStringNotContainsString('Anna', (string) $test->getBody());
    }

    public function testModelsSampleUsageAndDeleteLocks(): void
    {
        $this->createConnection();
        $created = $this->api->send('POST', '/data-sources/models', self::modelSpec());
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(409, $this->api->send('POST', '/data-sources/models', self::modelSpec())->getStatusCode());

        $conflict = $this->api->send('POST', '/data-sources/models', self::modelSpec('antrag'));
        self::assertSame(422, $conflict->getStatusCode(), 'Name eines Host-Modells');
        self::assertSame(422, $this->api->send('POST', '/data-sources/models', ['name' => 'leer'])->getStatusCode());

        self::assertContains('gesuch', ApiHarness::column($this->api->send('GET', '/data-models'), 'models', 'name'));
        self::assertSame(['gesuch'], ApiHarness::at($this->api->send('GET', '/data-sources/connections/antrag-api/usage'), 'models'));

        $this->respond(200, ['id' => 'A-1', 'kontakt' => ['mail' => 'p@example.org']]);
        $sample = $this->api->send('POST', '/data-sources/models/sample', ['model' => self::modelSpec('neu'), 'id' => 'A-1']);
        self::assertSame(200, $sample->getStatusCode(), (string) $sample->getBody());
        self::assertContains('kontakt.mail', ApiHarness::column($sample, 'paths', 'path'));

        $spec = self::modelSpec();
        $spec['label'] = 'Gesuche (neu)';
        $spec['name'] = 'wird-ignoriert';
        $updated = $this->api->send('PUT', '/data-sources/models/gesuch', $spec);
        self::assertSame('gesuch', ApiHarness::at($updated, 'name'));
        self::assertSame('Gesuche (neu)', ApiHarness::at($updated, 'label'));

        $blocked = $this->api->send('DELETE', '/data-sources/connections/antrag-api');
        self::assertSame(422, $blocked->getStatusCode(), 'Verbindung wird verwendet');

        self::assertSame(204, $this->api->send('DELETE', '/data-sources/models/gesuch')->getStatusCode());
        self::assertSame(204, $this->api->send('DELETE', '/data-sources/connections/antrag-api')->getStatusCode());
        self::assertSame(404, $this->api->send('GET', '/data-sources/models/gesuch')->getStatusCode());
    }

    public function testTriggersCheckNowAndUsage(): void
    {
        $this->createConnection();
        $this->api->send('POST', '/data-sources/models', self::modelSpec());
        $this->api->h->define(ApiDefinitions::antrag());
        $trigger = [
            'id' => 'neue-gesuche',
            'name' => 'Neue Gesuche',
            'model' => 'gesuch',
            'conditions' => [['field' => 'status', 'operator' => '=', 'value' => 'offen']],
            'definitionId' => 'antrag',
            'inputs' => ['antragId' => 'id'],
            'intervalMinutes' => 15,
        ];

        $created = $this->api->send('POST', '/data-sources/triggers', $trigger);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertFalse(ApiHarness::at($created, 'active'), 'neu = aus');
        self::assertSame(409, $this->api->send('POST', '/data-sources/triggers', $trigger)->getStatusCode());

        $tooFast = $this->api->send('PUT', '/data-sources/triggers/neue-gesuche', ['intervalMinutes' => 1] + $trigger);
        self::assertSame(422, $tooFast->getStatusCode());
        self::assertArrayHasKey('intervalMinutes', (array) ApiHarness::at($tooFast, 'error.details.fields'));
        self::assertSame(422, $this->api->send('PUT', '/data-sources/triggers/neue-gesuche', ['intervalMinutes' => 'oft'] + $trigger)->getStatusCode());

        $this->respond(200, ['data' => ['items' => [
            ['id' => 'A-1', 'status' => 'offen'], ['id' => 'A-2', 'status' => 'erledigt'], ['id' => 'A-3', 'status' => 'offen'],
        ]]]);
        $check = $this->api->send('POST', '/data-sources/triggers/neue-gesuche/check-now');
        self::assertSame(['id' => 'neue-gesuche', 'matches' => 2], ApiHarness::json($check));
        self::assertSame([], ApiHarness::at($this->api->send('GET', '/instances'), 'instances'), 'startet nichts');

        self::assertSame(['neue-gesuche'], ApiHarness::at($this->api->send('GET', '/data-sources/models/gesuch/usage'), 'triggers'));
        self::assertSame(422, $this->api->send('DELETE', '/data-sources/models/gesuch')->getStatusCode());
        self::assertSame(['neue-gesuche'], ApiHarness::column($this->api->send('GET', '/data-sources/triggers'), 'triggers', 'id'));
        self::assertSame(204, $this->api->send('DELETE', '/data-sources/triggers/neue-gesuche')->getStatusCode());
        self::assertSame(404, $this->api->send('POST', '/data-sources/triggers/neue-gesuche/check-now')->getStatusCode());
    }
}
