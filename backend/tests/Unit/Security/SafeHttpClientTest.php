<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestTransportException;
use WorkflowEngine\Security\BlockedUrlException;
use WorkflowEngine\Security\SafeHttpClient;
use WorkflowEngine\Security\UrlGuard;
use WorkflowEngine\Tests\Support\FakeResolver;

final class SafeHttpClientTest extends TestCase
{
    private FakeResolver $dns;

    protected function setUp(): void
    {
        $this->dns = new FakeResolver(['api.example.org' => ['93.184.216.34'], 'intern.example.org' => ['10.0.0.5']]);
    }

    /**
     * @param list<MockResponse>|\Closure $responses
     */
    private function client(array|\Closure $responses, RestOptions $options = new RestOptions()): SafeHttpClient
    {
        return new SafeHttpClient(new MockHttpClient($responses), new UrlGuard($this->dns), $options);
    }

    public function testPinsTheCheckedAddressAndDisablesAutomaticRedirects(): void
    {
        $seen = [];
        $client = $this->client(function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'resolve' => $options['resolve'] ?? null, 'max_redirects' => $options['max_redirects'] ?? null];

            return new MockResponse('{"ok":true}', ['http_code' => 200]);
        });

        $result = $client->request('GET', 'https://api.example.org/v1/antraege/A-1', ['headers' => ['Authorization' => 'Bearer x']]);

        self::assertSame(200, $result->status);
        self::assertSame('{"ok":true}', $result->body);
        self::assertSame('GET', $seen['method']);
        self::assertSame('https://api.example.org/v1/antraege/A-1', $seen['url']);
        self::assertSame(['api.example.org' => '93.184.216.34'], $seen['resolve']);
        self::assertSame(0, $seen['max_redirects']);
        self::assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function testRedirectsAreFollowedOnlyToCheckedTargets(): void
    {
        $client = $this->client([
            new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: /v2/antraege']]),
            new MockResponse('[]', ['http_code' => 200]),
        ]);
        self::assertSame('[]', $client->request('GET', 'https://api.example.org/v1/antraege')->body);

        $toInternal = $this->client([new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location: https://intern.example.org/admin']])]);
        $this->expectException(BlockedUrlException::class);
        $toInternal->request('GET', 'https://api.example.org/v1');
    }

    public function testTooManyRedirectsStop(): void
    {
        $loop = new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://api.example.org/loop']]);
        $client = $this->client([$loop, $loop, $loop, $loop, $loop]);

        $this->expectException(RestTransportException::class);
        $this->expectExceptionMessage('Weiterleitungen');
        $client->request('GET', 'https://api.example.org/loop');
    }

    public function testResponsesAboveTheSizeLimitAreAborted(): void
    {
        $client = $this->client([new MockResponse(str_repeat('x', 2048), ['http_code' => 200])], new RestOptions(maxResponseBytes: 1024));

        $this->expectException(RestTransportException::class);
        $this->expectExceptionMessage('zu gross');
        $client->request('GET', 'https://api.example.org/gross');
    }

    public function testTransportErrorsAreTechnicalErrorsWithoutSecrets(): void
    {
        $client = $this->client([new MockResponse('', ['error' => 'Operation timed out after 10000 ms'])]);

        try {
            $client->request('GET', 'https://api.example.org/langsam', ['headers' => ['Authorization' => 'Bearer geheim']]);
            self::fail('Keine Exception.');
        } catch (RestTransportException $e) {
            self::assertStringContainsString('api.example.org', $e->getMessage());
            self::assertStringNotContainsString('geheim', $e->getMessage());
        }
    }
}
