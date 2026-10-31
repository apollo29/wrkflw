<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\FieldType;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Rest\Actor;
use WorkflowEngine\Rest\AdminTrigger;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Rest\DataSourceAdmin;
use WorkflowEngine\Rest\DataSourceValidationException;
use WorkflowEngine\Rest\RestConnection;
use WorkflowEngine\Rest\RestDataModelSource;
use WorkflowEngine\Rest\RestFieldSpec;
use WorkflowEngine\Rest\RestModelSpec;
use WorkflowEngine\Rest\RestOperation;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestPersistence;
use WorkflowEngine\Security\SafeHttpClient;
use WorkflowEngine\Security\UrlGuard;
use WorkflowEngine\Tests\Support\EngineHarness;
use WorkflowEngine\Tests\Support\FakeResolver;
use WorkflowEngine\Tests\Support\InMemoryDataSourceStore;
use WorkflowEngine\Tests\Support\Models;
use WorkflowEngine\Tests\Support\RestFixtures;

/** Datenquellen ohne Code einrichten (F-133 bis F-145). */
final class DataSourceAdminTest extends TestCase
{
    private InMemoryDataSourceStore $store;

    private EngineHarness $h;

    /** @var list<MockResponse> */
    private array $responses = [];

    private Actor $admin;

    protected function setUp(): void
    {
        $this->store = new InMemoryDataSourceStore();
        $this->h = new EngineHarness();
        $this->h->addModel(Models::antrag());
        $this->admin = new Actor('u-7', 'Admin Muster');
    }

    private function admin(RestOptions $options = new RestOptions()): DataSourceAdmin
    {
        $resolver = new FakeResolver(['api.example.org' => ['93.184.216.34'], 'intern.example.org' => ['10.0.0.2']]);
        $http = new SafeHttpClient(
            new MockHttpClient(fn (): MockResponse => array_shift($this->responses) ?? new MockResponse('', ['http_code' => 500])),
            new UrlGuard($resolver, $options->allowedHosts),
            $options,
        );
        $restSource = new RestDataModelSource($this->store, $options);
        $registry = new DataModelRegistry([$this->h->models, $restSource]);
        $gateway = new \WorkflowEngine\Model\DataGateway($registry, new PersistenceRegistry([
            'db' => $this->h->storage,
            'rest' => new RestPersistence($http, $this->store, $this->store, RestFixtures::secrets(), $options),
        ]));

        return new DataSourceAdmin(
            connections: $this->store,
            models: $this->store,
            triggers: $this->store,
            audit: $this->store,
            secrets: RestFixtures::secrets(),
            guard: new UrlGuard($resolver, $options->allowedHosts),
            http: $http,
            registry: $registry,
            gateway: $gateway,
            definitions: $this->h->repo,
            usage: $this->h->repo,
            options: $options,
            clock: $this->h->clock,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private static function errors(\Closure $action): array
    {
        try {
            $action();
        } catch (DataSourceValidationException $e) {
            return $e->errors;
        }
        self::fail('Keine Prüfung schlug an.');
    }

    private function seedConnectionAndModel(): void
    {
        $this->admin()->saveConnection($this->admin, new RestConnection('antrag-api', 'Antrags-API', 'https://api.example.org/v1', AuthType::Bearer), 'token-123');
        $this->admin()->saveModel($this->admin, self::restModel());
    }

    private static function restModel(string $name = 'antrag_ext'): RestModelSpec
    {
        $spec = RestFixtures::model();

        return new RestModelSpec($name, 'Anträge (extern)', 'antrag-api', $spec->operations, $spec->fields, listPath: 'data.items', subset: ['archiviert' => 'false']);
    }

    public function testConnectionsStoreSecretsEncryptedAndNeverShowThem(): void
    {
        $saved = $this->admin()->saveConnection($this->admin, new RestConnection('antrag-api', 'Antrags-API', 'https://api.example.org/v1', AuthType::Bearer), 'token-123');

        self::assertTrue($saved->hasSecret());
        self::assertStringNotContainsString('token-123', (string) $saved->encryptedSecret);
        self::assertSame(['id' => 'antrag-api', 'name' => 'Antrags-API', 'baseUrl' => 'https://api.example.org/v1', 'authType' => 'bearer', 'authHeader' => null, 'secretStored' => true], $saved->toPublicArray());

        $renamed = $this->admin()->saveConnection($this->admin, new RestConnection('antrag-api', 'Neu', 'https://api.example.org/v2', AuthType::Bearer), null);
        self::assertSame($saved->encryptedSecret, $renamed->encryptedSecret, 'ohne neues Geheimnis bleibt das alte');

        $this->admin()->replaceSecret($this->admin, 'antrag-api', 'token-456');
        self::assertSame('token-456', RestFixtures::secrets()->decrypt((string) $this->store->findConnection('antrag-api')?->encryptedSecret)->reveal());

        $log = json_encode(array_map(static fn ($e) => [$e->action, $e->detail, $e->actor->name], $this->store->audit), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('token', $log);
        self::assertSame(['connection.created', 'connection.updated', 'connection.secret_replaced'], array_map(static fn ($e): string => $e->action, $this->store->audit));
        self::assertStringContainsString('Admin Muster', $log);
    }

    public function testConnectionUrlsAreChecked(): void
    {
        $errors = self::errors(fn () => $this->admin()->saveConnection($this->admin, new RestConnection('x', 'X', 'https://intern.example.org'), null));
        self::assertStringContainsString('interne', $errors['baseUrl'][0] ?? '');

        $errors = self::errors(fn () => $this->admin()->saveConnection($this->admin, new RestConnection('x y', '', 'http://api.example.org'), null));
        self::assertSame(['id', 'name', 'baseUrl'], array_keys($errors));

        $restricted = $this->admin(new RestOptions(allowedHosts: ['partner.example.com']));
        $errors = self::errors(fn () => $restricted->saveConnection($this->admin, new RestConnection('x', 'X', 'https://api.example.org'), null));
        self::assertStringContainsString('nicht freigegeben', $errors['baseUrl'][0] ?? '');
    }

    public function testConnectionTestShowsOnlyStatusAndDuration(): void
    {
        $this->seedConnectionAndModel();
        $this->responses[] = new MockResponse('{"secret":"inhalt"}', ['http_code' => 200]);

        $result = $this->admin()->testConnection('antrag-api');

        self::assertSame(200, $result->status);
        self::assertTrue($result->ok);
        self::assertNull($result->message);
        self::assertStringNotContainsString('inhalt', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testModelsAreValidatedAgainstHostModelsAndWritableRules(): void
    {
        $this->admin()->saveConnection($this->admin, new RestConnection('antrag-api', 'Antrags-API', 'https://api.example.org/v1'), null);

        $errors = self::errors(fn () => $this->admin()->saveModel($this->admin, self::restModel('antrag')));
        self::assertStringContainsString('Host-App', $errors['name'][0] ?? '');

        $readOnly = new RestModelSpec('nur_lesen', 'X', 'antrag-api', ['load' => new RestOperation('GET', '/x/{id}')], [
            new RestFieldSpec('id', 'ID', FieldType::Text, 'id'),
            new RestFieldSpec('status', 'Status', FieldType::Text, 'status', writable: true),
        ]);
        $errors = self::errors(fn () => $this->admin()->saveModel($this->admin, $readOnly));
        self::assertStringContainsString('Ändern', $errors['fields.status'][0] ?? '');

        $noWrites = $this->admin(new RestOptions(allowWrites: false));
        $errors = self::errors(fn () => $noWrites->saveModel($this->admin, self::restModel()));
        self::assertArrayHasKey('fields.status', $errors);

        $unknownConnection = new RestModelSpec('x', 'X', 'gibt-es-nicht', [], [new RestFieldSpec('id', 'ID', FieldType::Text, 'id')]);
        self::assertArrayHasKey('connection', self::errors(fn () => $this->admin()->saveModel($this->admin, $unknownConnection)));
    }

    public function testUsedThingsCannotBeDeleted(): void
    {
        $this->seedConnectionAndModel();
        $this->h->define([
            'id' => 'extern', 'startStep' => 'lesen',
            'steps' => ['lesen' => ['action' => 'check_data', 'config' => ['entity' => 'antrag_ext', 'id' => '1', 'field' => 'status', 'fields' => ['betrag']]]],
        ], validate: false);

        self::assertArrayHasKey('connection', self::errors(fn () => $this->admin()->deleteConnection($this->admin, 'antrag-api')));
        $errors = self::errors(fn () => $this->admin()->deleteModel($this->admin, 'antrag_ext'));
        self::assertStringContainsString('extern', $errors['model'][0] ?? '');

        $withoutUsedField = new RestModelSpec('antrag_ext', 'X', 'antrag-api', self::restModel()->operations, [
            new RestFieldSpec('id', 'ID', FieldType::Text, 'id'),
            new RestFieldSpec('status', 'Status', FieldType::Text, 'status'),
        ]);
        $errors = self::errors(fn () => $this->admin()->saveModel($this->admin, $withoutUsedField));
        self::assertStringContainsString('extern', $errors['fields.betrag'][0] ?? '');

        $usage = $this->admin()->modelUsage('antrag_ext');
        self::assertSame('extern', $usage['definitions'][0]->definitionId);
        self::assertSame(['status', 'betrag'], $usage['definitions'][0]->fields);
        self::assertSame(['antrag_ext'], $this->admin()->connectionUsage('antrag-api'));
    }

    public function testTriggersAreValidatedAndCheckNowStartsNothing(): void
    {
        $this->seedConnectionAndModel();
        $this->h->define([
            'id' => 'pruefen', 'startStep' => 'a',
            'inputs' => [['name' => 'antrag_id', 'required' => true], ['name' => 'kontakt']],
            'steps' => ['a' => ['type' => 'interactive', 'transitions' => [['to' => 'b', 'event' => 'submit']]], 'b' => []],
        ]);

        $bad = new AdminTrigger('t1', 'Offene', 'antrag_ext', [new Condition('farbe', ConditionOperator::Eq, 'x')], 'pruefen', ['kontakt' => 'mail'], intervalMinutes: 1, lockDays: 0);
        $errors = self::errors(fn () => $this->admin()->saveTrigger($this->admin, $bad));
        self::assertSame(['conditions', 'inputs.antrag_id', 'intervalMinutes', 'lockDays'], array_keys($errors));

        $good = new AdminTrigger('t1', 'Offene', 'antrag_ext', [new Condition('status', ConditionOperator::Eq, 'offen')], 'pruefen', ['antrag_id' => 'id', 'kontakt' => 'mail']);
        $saved = $this->admin()->saveTrigger($this->admin, $good);
        self::assertFalse($saved->active);

        $this->responses[] = new MockResponse((string) json_encode(['data' => ['items' => [RestFixtures::antragJson('A-1'), RestFixtures::antragJson('A-2')]]]), ['http_code' => 200]);
        self::assertSame(2, $this->admin()->checkTrigger($good));
        self::assertSame([], $this->h->repo->allInstances());
    }

    public function testSampleShowsTheTreeForFieldAdoptionWithoutStoringIt(): void
    {
        $this->seedConnectionAndModel();
        $this->responses[] = new MockResponse((string) json_encode(RestFixtures::antragJson()), ['http_code' => 200]);

        $sample = $this->admin()->sample(self::restModel(), 'A-1024');

        self::assertSame(200, $sample->status);
        self::assertContains(['path' => 'kontakt.mail', 'type' => 'text'], array_map(static fn (array $p): array => ['path' => $p['path'], 'type' => $p['type']], $sample->paths));
        self::assertContains(['path' => 'betrag', 'type' => 'number'], array_map(static fn (array $p): array => ['path' => $p['path'], 'type' => $p['type']], $sample->paths));
        self::assertStringNotContainsString('person@example.org', json_encode($this->store->audit, JSON_THROW_ON_ERROR));
    }
}
