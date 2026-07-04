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
use WorkflowEngine\Http\TemplateController;
use WorkflowEngine\Tests\Support\InMemoryTemplateRepository;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

#[CoversClass(TemplateController::class)]
final class TemplateApiTest extends TestCase
{
    private InMemoryTemplateRepository $templates;
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;

    protected function setUp(): void
    {
        $repo = new InMemoryWorkflowRepository();
        $this->templates = new InMemoryTemplateRepository();
        $engine = new WorkflowEngine($repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
        $this->app = ApiFactory::create($engine, $repo, null, null, $this->templates);
    }

    public function testSaveListGetRoundtrip(): void
    {
        $create = $this->send('POST', '/templates/welcome', [
            'name' => 'Willkommen',
            'subject' => 'Hallo {{name}}',
            'body' => '<h2>Hallo {{name}}</h2>',
        ]);
        self::assertSame(201, $create->getStatusCode());
        self::assertSame('welcome', $this->decode($create)['id']);

        $list = $this->decode($this->send('GET', '/templates'));
        $templates = $list['templates'] ?? null;
        self::assertIsArray($templates);
        self::assertCount(1, $templates);

        $get = $this->send('GET', '/templates/welcome');
        self::assertSame(200, $get->getStatusCode());
        $tpl = $this->decode($get);
        self::assertSame('Willkommen', $tpl['name']);
        self::assertSame('Hallo {{name}}', $tpl['subject']);
        self::assertSame('<h2>Hallo {{name}}</h2>', $tpl['body']);
    }

    public function testGetUnknownReturns404(): void
    {
        self::assertSame(404, $this->send('GET', '/templates/ghost')->getStatusCode());
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function send(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
        }

        return $this->app->handle($request);
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $out = [];
        foreach ($data as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
