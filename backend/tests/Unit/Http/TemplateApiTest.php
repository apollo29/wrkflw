<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Definition\WorkflowDefinition;
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
    private InMemoryWorkflowRepository $repo;
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->templates = new InMemoryTemplateRepository();
        $engine = new WorkflowEngine($this->repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
        $this->app = ApiFactory::create($engine, $this->repo, null, null, $this->templates);
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

    public function testSaveWithTypeAndFilterByType(): void
    {
        $this->send('POST', '/templates/mail', ['name' => 'Mail', 'subject' => 'S', 'body' => 'B', 'type' => 'email']);
        $this->send('POST', '/templates/page', ['name' => 'Seite', 'body' => '<h1>Hi</h1>', 'type' => 'page']);

        $pages = $this->decode($this->send('GET', '/templates?type=page'))['templates'] ?? null;
        self::assertIsArray($pages);
        self::assertCount(1, $pages);
        self::assertIsArray($pages[0]);
        self::assertSame('page', $pages[0]['id'] ?? null);
        self::assertSame('page', $pages[0]['type'] ?? null);

        $detail = $this->decode($this->send('GET', '/templates/page'));
        self::assertSame('page', $detail['type'] ?? null);
    }

    public function testDeleteRemovesTemplate(): void
    {
        $this->send('POST', '/templates/tmp', ['name' => 'Tmp', 'subject' => 'S', 'body' => 'B']);
        self::assertSame(200, $this->send('GET', '/templates/tmp')->getStatusCode());

        $del = $this->send('DELETE', '/templates/tmp');
        self::assertSame(200, $del->getStatusCode());
        self::assertTrue($this->decode($del)['deleted'] ?? false);

        self::assertSame(404, $this->send('GET', '/templates/tmp')->getStatusCode());
    }

    public function testUsageListsReferencingSteps(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'mail',
            'steps' => [
                'mail' => ['type' => 'automatic', 'action' => 'send_email', 'config' => ['templateId' => 'welcome']],
            ],
        ]));

        $usage = $this->decode($this->send('GET', '/templates/welcome/usage'))['usage'] ?? null;
        self::assertIsArray($usage);
        self::assertCount(1, $usage);
        self::assertIsArray($usage[0]);
        self::assertSame('flow', $usage[0]['definitionId'] ?? null);
        self::assertSame('mail', $usage[0]['step'] ?? null);
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
