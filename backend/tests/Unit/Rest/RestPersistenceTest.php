<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestPersistence;
use WorkflowEngine\Rest\RestRequestException;
use WorkflowEngine\Rest\RestTransportException;
use WorkflowEngine\Security\SafeHttpClient;
use WorkflowEngine\Security\UrlGuard;
use WorkflowEngine\Tests\Support\FakeResolver;
use WorkflowEngine\Tests\Support\InMemoryDataSourceStore;
use WorkflowEngine\Tests\Support\RestFixtures;

/** REST-Persistenz (F-131): Pfade, Feldzuordnung, Teilmenge, Anmeldung, Fehlerarten. */
final class RestPersistenceTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $requests = [];

    /** @var list<MockResponse> */
    private array $responses = [];

    private InMemoryDataSourceStore $store;

    private DataModel $model;

    private ChangeOrigin $origin;

    protected function setUp(): void
    {
        $this->store = new InMemoryDataSourceStore();
        $this->store->saveConnection(RestFixtures::connection());
        $this->store->saveModel(RestFixtures::model(creatable: true));
        $this->model = RestFixtures::model(creatable: true)->toDataModel(true);
        $this->origin = new ChangeOrigin('antrag-pruefung', 3, 'inst-1', 'speichern');
    }

    private function persistence(): RestPersistence
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = [
                'method' => $method,
                'url' => $url,
                'headers' => array_values(array_filter((array) ($options['headers'] ?? []), is_string(...))),
                'body' => is_string($options['body'] ?? null) ? $options['body'] : '',
            ];

            return array_shift($this->responses) ?? new MockResponse('', ['http_code' => 500]);
        });
        $http = new SafeHttpClient($mock, new UrlGuard(new FakeResolver(['api.example.org' => ['93.184.216.34']])), new RestOptions());

        return new RestPersistence($http, $this->store, $this->store, RestFixtures::secrets(), new RestOptions(maxResults: 2));
    }

    /**
     * @param list<string> $headers
     */
    private function respond(int $status, mixed $json = null, array $headers = []): void
    {
        $this->responses[] = new MockResponse($json === null ? '' : json_encode($json, JSON_THROW_ON_ERROR), ['http_code' => $status, 'response_headers' => $headers]);
    }

    public function testLoadMapsNestedPathsAndHidesUndescribedFields(): void
    {
        $this->respond(200, RestFixtures::antragJson());

        $row = $this->persistence()->load($this->model, 'A 1024/x');

        self::assertSame(['id' => 'A-1024', 'status' => 'offen', 'betrag' => 1250, 'mail' => 'person@example.org', 'geprueft_am' => null], $row);
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('https://api.example.org/v1/antraege/A%201024%2Fx?archiviert=false', $this->requests[0]['url']);
        self::assertContains('Authorization: Bearer token-123', $this->requests[0]['headers']);
    }

    public function testNotFoundIsAnAnswer(): void
    {
        $this->respond(404, ['error' => 'not found']);

        self::assertNull($this->persistence()->load($this->model, 'A-9'));
    }

    public function testFindSendsEqualityConditionsAndFiltersTheList(): void
    {
        $this->respond(200, ['data' => ['items' => [
            RestFixtures::antragJson('A-1', 1250),
            RestFixtures::antragJson('A-2', 300),
            RestFixtures::antragJson('A-3', 5000),
            RestFixtures::antragJson('A-4', 7000),
        ]]]);

        $rows = $this->persistence()->find($this->model, [
            new Condition('status', ConditionOperator::Eq, 'offen'),
            new Condition('betrag', ConditionOperator::Gt, 1000),
        ], 10);

        self::assertSame(['A-1', 'A-3'], array_column($rows, 'id'), 'gefiltert und auf maxResults begrenzt');
        self::assertSame('https://api.example.org/v1/antraege?archiviert=false&status=offen', $this->requests[0]['url']);
    }

    public function testUpdateSendsOnlyTheGivenFieldsAsNestedJson(): void
    {
        $this->respond(200, ['ok' => true]);
        $model = DataModel::define('antrag', 'Anträge')
            ->text('mail', 'E-Mail', 'kontakt.mail')->writable()
            ->text('status', 'Status')->writable()
            ->persistence('rest')->updatable()->build();

        self::assertTrue($this->persistence()->update($model, 'A-1', ['status' => 'freigegeben', 'mail' => 'neu@example.org'], $this->origin));
        self::assertSame('PATCH', $this->requests[0]['method']);
        self::assertSame('https://api.example.org/v1/antraege/A-1?archiviert=false', $this->requests[0]['url']);
        self::assertSame(['status' => 'freigegeben', 'kontakt' => ['mail' => 'neu@example.org']], json_decode($this->requests[0]['body'], true));

        $this->respond(404);
        self::assertFalse($this->persistence()->update($model, 'A-9', ['status' => 'x'], $this->origin));
    }

    public function testCreateReadsTheNewIdFromTheResponseOrLocation(): void
    {
        $this->respond(201, ['id' => 'A-77', 'status' => 'neu']);
        self::assertSame('A-77', $this->persistence()->create($this->model, null, ['status' => 'neu'], $this->origin));
        self::assertSame('POST', $this->requests[0]['method']);

        $this->respond(201, null, ['Location: https://api.example.org/v1/antraege/A-78']);
        self::assertSame('A-78', $this->persistence()->create($this->model, null, ['status' => 'neu'], $this->origin));
    }

    public function testAuthenticationVariants(): void
    {
        $cases = [
            [RestFixtures::connection(AuthType::ApiKey, 'k-1', 'X-Api-Key'), 'X-Api-Key: k-1'],
            [RestFixtures::connection(AuthType::Basic, 'user:pw'), 'Authorization: Basic ' . base64_encode('user:pw')],
        ];
        foreach ($cases as [$connection, $header]) {
            $this->store->saveConnection($connection);
            $this->requests = [];
            $this->respond(200, RestFixtures::antragJson());
            $this->persistence()->load($this->model, 'A-1');
            self::assertContains($header, $this->requests[0]['headers']);
        }

        $this->store->saveConnection(RestFixtures::connection(AuthType::None, null));
        $this->requests = [];
        $this->respond(200, RestFixtures::antragJson());
        $this->persistence()->load($this->model, 'A-1');
        self::assertSame([], array_filter($this->requests[0]['headers'], static fn (string $h): bool => str_starts_with($h, 'Authorization')));
    }

    public function testErrorKinds(): void
    {
        foreach ([[503, RestTransportException::class], [429, RestTransportException::class], [401, RestRequestException::class], [422, RestRequestException::class]] as [$status, $expected]) {
            $this->respond($status, ['message' => 'Personendaten im Fehlertext']);
            try {
                $this->persistence()->load($this->model, 'A-1');
                self::fail("Kein Fehler bei {$status}.");
            } catch (RestTransportException|RestRequestException $e) {
                self::assertSame($expected, $e::class);
                self::assertStringContainsString((string) $status, $e->getMessage());
                self::assertStringNotContainsString('Personendaten', $e->getMessage());
            }
        }

        $this->responses[] = new MockResponse('<html>kein JSON</html>', ['http_code' => 200]);
        $this->expectException(RestRequestException::class);
        $this->persistence()->load($this->model, 'A-1');
    }

    public function testOperationsWithoutPathAreUnavailable(): void
    {
        $this->store->saveModel(RestFixtures::model(creatable: false));

        $this->expectException(RestRequestException::class);
        $this->expectExceptionMessage('Anlegen');
        $this->persistence()->create($this->model, null, ['status' => 'x'], $this->origin);
    }
}
