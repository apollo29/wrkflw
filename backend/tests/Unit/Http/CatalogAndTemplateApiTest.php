<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Tests\Support\ApiDefinitions;
use WorkflowEngine\Tests\Support\ApiHarness;

/** Aktionen, Datenmodelle, Datei-Prüfungen und Vorlagen. */
final class CatalogAndTemplateApiTest extends TestCase
{
    private ApiHarness $api;

    protected function setUp(): void
    {
        $this->api = new ApiHarness();
    }

    public function testActionsDataModelsAndUploadHandlers(): void
    {
        $actions = $this->api->send('GET', '/actions');
        self::assertContains('send_email', ApiHarness::column($actions, 'actions', 'key'));

        $models = $this->api->send('GET', '/data-models');
        self::assertSame(['antrag'], ApiHarness::column($models, 'models', 'name'));
        self::assertContains('betrag', ApiHarness::column($models, 'models.0.fields', 'name'));

        $writable = $this->api->send('GET', '/data-models?writable=1');
        self::assertSame(['status', 'geprueft_am'], ApiHarness::column($writable, 'models.0.fields', 'name'));

        $handlers = $this->api->send('GET', '/upload-handlers');
        self::assertSame([['key' => 'pdf_max_5mb', 'label' => 'PDF bis 5 MB', 'description' => 'Nur PDF-Dateien bis 5 MB.']], ApiHarness::at($handlers, 'handlers'));
    }

    public function testTemplateLifecycle(): void
    {
        $saved = $this->api->send('POST', '/templates/eingang', ['name' => 'Eingang', 'subject' => 'Danke', 'body' => '<p>Hallo {{name}}</p>']);
        self::assertSame(201, $saved->getStatusCode());
        self::assertSame('email', ApiHarness::at($saved, 'type'));
        $this->api->send('POST', '/templates/seite', ['type' => 'page', 'body' => '<p>x</p>']);

        self::assertSame(['eingang', 'seite'], ApiHarness::column($this->api->send('GET', '/templates'), 'templates', 'id'));
        self::assertSame(['seite'], ApiHarness::column($this->api->send('GET', '/templates?type=page'), 'templates', 'id'));
        self::assertSame('seite', ApiHarness::at($this->api->send('GET', '/templates/seite'), 'name'), 'ohne Namen gilt die ID');
        self::assertSame(404, $this->api->send('GET', '/templates/gibts-nicht')->getStatusCode());
        self::assertSame(422, $this->api->send('GET', '/templates?type=fax')->getStatusCode());
        self::assertSame(422, $this->api->send('POST', '/templates/a%20b', ['body' => 'x'])->getStatusCode());
    }

    public function testDeleteIsBlockedWhileUsed(): void
    {
        $this->api->send('POST', '/templates/eingang', ['subject' => 'Danke', 'body' => 'x']);
        $definition = ApiDefinitions::antragWith('ende', [
            'action' => 'send_email',
            'config' => ['to' => 'a@example.org', 'templateId' => 'eingang'],
        ], false);
        $this->api->h->define($definition, 1, false);

        $usage = $this->api->send('GET', '/templates/eingang/usage');
        self::assertSame([['definitionId' => 'antrag', 'version' => 1, 'step' => 'ende']], ApiHarness::at($usage, 'usage'));

        $blocked = $this->api->send('DELETE', '/templates/eingang');
        self::assertSame(409, $blocked->getStatusCode());
        self::assertSame('in_use', ApiHarness::at($blocked, 'error.code'));

        $this->api->send('POST', '/templates/frei', ['body' => 'x']);
        self::assertSame(204, $this->api->send('DELETE', '/templates/frei')->getStatusCode());
        self::assertSame(404, $this->api->send('DELETE', '/templates/frei')->getStatusCode());
    }
}
