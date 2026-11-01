<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WorkflowEngine\Contracts\AccessResolverInterface;
use WorkflowEngine\Contracts\UploadHandler;
use WorkflowEngine\Contracts\UploadHandlerCatalogInterface;
use WorkflowEngine\Http\Access;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\ApiServices;
use WorkflowEngine\Http\DataSources;
use WorkflowEngine\Model\DataCatalog;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Rest\Actor;
use WorkflowEngine\Rest\DataSourceAdmin;
use WorkflowEngine\Rest\RestDataModelSource;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestPersistence;
use WorkflowEngine\Security\SafeHttpClient;
use WorkflowEngine\Security\UrlGuard;

/**
 * Die API auf In-Memory-Fakes. Anfragen tragen `X-Test-User` (`admin` oder `user`);
 * ohne diesen Header gilt man als nicht angemeldet.
 */
final class ApiHarness
{
    public readonly EngineHarness $h;

    public readonly InMemoryDataSourceStore $store;

    /** @var list<MockResponse> Antworten des externen REST-Systems */
    public array $responses = [];

    /** @var App<\Psr\Container\ContainerInterface|null> */
    public readonly App $app;

    public function __construct(bool $withDataSources = true)
    {
        $this->h = new EngineHarness();
        $this->h->addModel(Models::antrag());
        $this->store = new InMemoryDataSourceStore();

        $options = new RestOptions();
        $resolver = new FakeResolver(['api.example.org' => ['93.184.216.34'], 'intern.example.org' => ['10.0.0.2']]);
        $http = new SafeHttpClient(
            new MockHttpClient(fn (): MockResponse => array_shift($this->responses) ?? new MockResponse('', ['http_code' => 500])),
            new UrlGuard($resolver),
            $options,
        );
        $registry = new DataModelRegistry([$this->h->models, new RestDataModelSource($this->store, $options)]);
        $gateway = new DataGateway($registry, new PersistenceRegistry([
            'db' => $this->h->storage,
            'rest' => new RestPersistence($http, $this->store, $this->store, RestFixtures::secrets(), $options),
        ]));
        $admin = new DataSourceAdmin(
            $this->store,
            $this->store,
            $this->store,
            $this->store,
            RestFixtures::secrets(),
            new UrlGuard($resolver),
            $http,
            $registry,
            $gateway,
            $this->h->repo,
            $this->h->repo,
            $options,
            $this->h->clock,
        );

        $services = new ApiServices(
            engine: $this->h->engine,
            workflows: $this->h->repo,
            definitions: $this->h->repo,
            instances: $this->h->repo,
            templates: $this->h->templates,
            validator: $this->h->validator,
            actions: $this->h->actions,
            catalog: new DataCatalog($registry),
            uploadHandlers: new class () implements UploadHandlerCatalogInterface {
                public function handlers(): array
                {
                    return [new UploadHandler('pdf_max_5mb', 'PDF bis 5 MB', 'Nur PDF-Dateien bis 5 MB.')];
                }
            },
            dataSources: $withDataSources ? new DataSources($admin, $this->store, $this->store, $this->store, $this->store) : null,
        );

        $this->app = ApiFactory::create($services, new class () implements AccessResolverInterface {
            public function resolve(ServerRequestInterface $request): ?Access
            {
                return match ($request->getHeaderLine('X-Test-User')) {
                    'admin' => new Access(new Actor('u-1', 'Admin Muster'), true),
                    'user' => new Access(new Actor('u-2', 'Nutzer Muster'), false),
                    default => null,
                };
            }
        });
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    public function send(string $method, string $path, ?array $body = null, string $user = 'admin', array $headers = []): ResponseInterface
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, '');
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($query !== '') {
            parse_str($query, $params);
            $request = $request->withQueryParams($params);
        }
        if ($user !== '') {
            $request = $request->withHeader('X-Test-User', $user);
        }
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new \LogicException('Keine JSON-Antwort: ' . $response->getBody());
    }

    /** Wert in einer JSON-Antwort über einen Pfad wie `error.code` oder `instances.0.id`. */
    public static function at(ResponseInterface $response, string $path): mixed
    {
        $data = self::json($response);
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    public static function str(ResponseInterface $response, string $path): string
    {
        $value = self::at($response, $path);

        return is_scalar($value) ? (string) $value : throw new \LogicException("Kein Text unter '{$path}'.");
    }

    /**
     * @return list<mixed>
     */
    public static function column(ResponseInterface $response, string $listPath, string $key): array
    {
        $list = self::at($response, $listPath);

        return is_array($list) ? array_values(array_map(static fn (mixed $row): mixed => is_array($row) ? ($row[$key] ?? null) : null, $list)) : [];
    }
}
