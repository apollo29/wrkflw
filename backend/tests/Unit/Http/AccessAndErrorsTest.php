<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Http\ApiKeyAccessResolver;
use WorkflowEngine\Http\ErrorMapper;
use WorkflowEngine\Http\OpenAccessResolver;
use WorkflowEngine\Tests\Support\ApiHarness;

/** Zugriff über den Port (F-104) und einheitliches Fehlerformat (ADR 11). */
final class AccessAndErrorsTest extends TestCase
{
    public function testWithoutAccessEveryRouteIs401(): void
    {
        $api = new ApiHarness();
        foreach (['GET /workflows', 'GET /instances', 'GET /data-sources/connections', 'POST /workflows/x/instances'] as $route) {
            [$method, $path] = explode(' ', $route);
            $response = $api->send($method, $path, null, '');
            self::assertSame(401, $response->getStatusCode(), $route);
            self::assertSame('unauthorized', ApiHarness::at($response, 'error.code'));
            self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        }
    }

    public function testDataSourcesNeedTheAdminRight(): void
    {
        $api = new ApiHarness();

        self::assertSame(200, $api->send('GET', '/workflows', null, 'user')->getStatusCode());
        $forbidden = $api->send('GET', '/data-sources/connections', null, 'user');
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('forbidden', ApiHarness::at($forbidden, 'error.code'));
        self::assertSame(403, $api->send('POST', '/data-sources/connections', ['id' => 'x'], 'user')->getStatusCode());
        self::assertSame(200, $api->send('GET', '/data-sources/connections')->getStatusCode());
    }

    public function testWithoutDataSourcesThereAreNoSuchRoutes(): void
    {
        $api = new ApiHarness(withDataSources: false);

        self::assertSame(404, $api->send('GET', '/data-sources/connections')->getStatusCode());
    }

    public function testUnknownRouteAndMethod(): void
    {
        $api = new ApiHarness();

        $unknown = $api->send('GET', '/gibts-nicht');
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame('not_found', ApiHarness::at($unknown, 'error.code'));
        $method = $api->send('PATCH', '/workflows');
        self::assertSame(405, $method->getStatusCode());
        self::assertSame('method_not_allowed', ApiHarness::at($method, 'error.code'));
    }

    public function testUnexpectedErrorsRevealNothing(): void
    {
        $response = ErrorMapper::respond(new \RuntimeException('SQLSTATE: Passwort geheim123'), (new ResponseFactory())->createResponse());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['error' => ['code' => 'internal_error', 'message' => 'Interner Serverfehler.']], ApiHarness::json($response));
    }

    public function testApiKeyResolver(): void
    {
        $resolver = new ApiKeyAccessResolver('s3cret');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/workflows');

        self::assertNull($resolver->resolve($request));
        self::assertNull($resolver->resolve($request->withHeader('Authorization', 'Bearer falsch')));
        self::assertNull($resolver->resolve($request->withHeader('Authorization', 's3cret')));
        $access = $resolver->resolve($request->withHeader('Authorization', 'Bearer s3cret'));
        self::assertNotNull($access);
        self::assertTrue($access->admin);
        self::assertSame('api', $access->actor->id);

        self::assertTrue((new OpenAccessResolver())->resolve($request)->admin);
        $this->expectException(\InvalidArgumentException::class);
        new ApiKeyAccessResolver('');
    }
}
