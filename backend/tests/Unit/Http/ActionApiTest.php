<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Http\ActionController;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Tests\Support\ArrayMailer;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;
use WorkflowEngine\Tests\Support\MarkVipAction;

#[CoversClass(ActionController::class)]
final class ActionApiTest extends TestCase
{
    public function testListActionsReturnsKeysAndConfigSchema(): void
    {
        $repo = new InMemoryWorkflowRepository();
        $actions = new ActionRegistry();
        $actions->register('send_email', new SendEmailAction(new ArrayMailer()));
        $actions->register('mark_vip', new MarkVipAction());
        $engine = new WorkflowEngine($repo, $actions, new SymfonyExpressionEvaluator());
        $app = ApiFactory::create($engine, $repo, null, $actions);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/actions');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $catalog = $data['actions'] ?? null;
        self::assertIsArray($catalog);

        $byKey = [];
        foreach ($catalog as $entry) {
            if (is_array($entry) && isset($entry['key']) && is_string($entry['key'])) {
                $byKey[$entry['key']] = $entry;
            }
        }

        self::assertArrayHasKey('send_email', $byKey);
        self::assertArrayHasKey('mark_vip', $byKey);

        $sendEmailConfig = $byKey['send_email']['config'] ?? null;
        self::assertIsArray($sendEmailConfig);
        $fieldNames = [];
        $typeByName = [];
        foreach ($sendEmailConfig as $field) {
            if (is_array($field) && isset($field['name']) && is_string($field['name'])) {
                $fieldNames[] = $field['name'];
                $typeByName[$field['name']] = $field['type'] ?? null;
            }
        }
        self::assertSame(['templateId', 'to', 'subject', 'body'], $fieldNames);
        // Der Body ist ein HTML-Template (WYSIWYG im Editor); templateId referenziert eine Vorlage.
        self::assertSame('html', $typeByName['body'] ?? null);
        self::assertSame('template-ref', $typeByName['templateId'] ?? null);

        // Action ohne Schema -> leere config.
        self::assertSame([], $byKey['mark_vip']['config'] ?? null);
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
