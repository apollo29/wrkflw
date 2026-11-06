<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Http\Access;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Tests\Support\ApiDefinitions;
use WorkflowEngine\Tests\Support\ApiHarness;

/**
 * Die API in einer bestehenden Slim-Anwendung der Host-App (ADR 11, Nachtrag 3.1):
 * eigene Gruppe mit Präfix, Rollen je Route über einen Guard, Fehlerformat nur dort.
 */
final class MountTest extends TestCase
{
    /** @var list<string> */
    private array $guarded = [];

    /**
     * @return App<\Psr\Container\ContainerInterface|null>|App<ContainerInterface>
     */
    private function host(?\Closure $guard = null, ?ContainerInterface $container = null): App
    {
        $h = new ApiHarness();
        $app = AppFactory::create(null, $container);
        $app->group('/api/v1', function (RouteCollectorProxyInterface $v1) use ($h, $guard): void {
            $v1->get('/host', function (ServerRequestInterface $request, ResponseInterface $response): ResponseInterface {
                $response->getBody()->write('host');

                return $response;
            });
            $v1->get('/host-fehler', function (): never {
                throw new \LogicException('Fehler der Host-App');
            });
            ApiFactory::mount($v1, '/wf', $h->services, $h->access, $guard);
        });
        $app->addRoutingMiddleware();

        return $app;
    }

    /**
     * @param App<\Psr\Container\ContainerInterface|null>|App<ContainerInterface> $app
     * @param array<string, mixed>|null                                            $body
     */
    private static function send(App $app, string $method, string $path, string $user = 'admin', ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($user !== '') {
            $request = $request->withHeader('X-Test-User', $user);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withParsedBody($body);
        }

        return $app->handle($request);
    }

    /**
     * Host-Apps mit DI-Container (PHP-DI u. a.): Slim bindet Gruppen-Closures an den
     * Container. Eine `static`-Closure lässt sich nicht binden, der Aufbau der
     * Routentabelle scheiterte (3.1.0).
     */
    public function testMountWorksInAnAppWithAContainer(): void
    {
        $container = new class () implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new class ("Kein Eintrag {$id}") extends \RuntimeException implements \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $app = $this->host(null, $container);

        self::assertSame(201, self::send($app, 'POST', '/api/v1/wf/workflows/antrag', 'admin', ['definition' => ApiDefinitions::antrag()])->getStatusCode());
        self::assertSame('host', (string) self::send($app, 'GET', '/api/v1/host', '')->getBody());
    }

    public function testRoutesLiveUnderThePrefixNextToTheHostRoutes(): void
    {
        $app = $this->host();

        self::assertSame(201, self::send($app, 'POST', '/api/v1/wf/workflows/antrag', 'admin', ['definition' => ApiDefinitions::antrag()])->getStatusCode());
        self::assertSame('antrag', ApiHarness::at(self::send($app, 'GET', '/api/v1/wf/workflows'), 'definitions.0.id'));
        self::assertSame('host', (string) self::send($app, 'GET', '/api/v1/host', '')->getBody());
    }

    public function testAccessAndErrorFormatApplyOnlyInsideTheGroup(): void
    {
        $app = $this->host();

        $unauthorized = self::send($app, 'GET', '/api/v1/wf/workflows', '');
        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertSame('unauthorized', ApiHarness::at($unauthorized, 'error.code'));
        $missing = self::send($app, 'GET', '/api/v1/wf/workflows/gibts-nicht');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('not_found', ApiHarness::at($missing, 'error.code'));

        // Fehler der Host-App behandelt weiter die Host-App.
        $this->expectException(\LogicException::class);
        self::send($app, 'GET', '/api/v1/host-fehler', '');
    }

    public function testTheGuardDecidesPerRouteAfterTheAccessCheck(): void
    {
        $app = $this->host(function (string $route, Access $access): bool {
            $this->guarded[] = $route;

            return $access->admin || $route === 'GET /workflows';
        });

        self::assertSame(200, self::send($app, 'GET', '/api/v1/wf/workflows', 'user')->getStatusCode());
        $forbidden = self::send($app, 'POST', '/api/v1/wf/workflows/antrag', 'user', ['definition' => ApiDefinitions::antrag()]);
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', ApiHarness::at($forbidden, 'error.code'));
        self::send($app, 'GET', '/api/v1/wf/instances/x/history', 'admin');
        // Ohne Anmeldung kommt der Guard gar nicht erst zum Zug.
        self::assertSame(401, self::send($app, 'GET', '/api/v1/wf/workflows', '')->getStatusCode());
        self::assertSame(['GET /workflows', 'POST /workflows/{def}', 'GET /instances/{id}/history'], $this->guarded);
    }

    public function testDataSourcesStayAdminOnlyAndUseTheirFullRouteKey(): void
    {
        $app = $this->host(function (string $route): bool {
            $this->guarded[] = $route;

            return true;
        });

        self::assertSame(403, self::send($app, 'GET', '/api/v1/wf/data-sources/connections', 'user')->getStatusCode());
        self::assertSame(200, self::send($app, 'GET', '/api/v1/wf/data-sources/connections')->getStatusCode());
        // Ohne Admin-Recht endet die Anfrage schon vor dem Guard.
        self::assertSame(['GET /data-sources/connections'], $this->guarded);
    }

    public function testTheRouteKeysMatchTheRouteList(): void
    {
        $keys = [];
        $app = $this->host(static function (string $route) use (&$keys): bool {
            $keys[] = $route;

            return false;
        });
        foreach (ApiFactory::routeList() as $route) {
            [$method, $path] = explode(' ', $route);
            self::send($app, $method, '/api/v1/wf' . str_replace(['{def}', '{id}', '{version}', '{name}'], ['a', 'b', '1', 'c'], $path));
        }

        self::assertSame(ApiFactory::routeList(), $keys);
    }
}
