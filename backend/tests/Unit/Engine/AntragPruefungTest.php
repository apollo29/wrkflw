<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\EngineHarness;
use WorkflowEngine\Tests\Support\Models;
use WorkflowEngine\Tests\Unit\Definition\DefinitionParserTest;

/**
 * Der Beispielablauf aus Abschnitt 9.13, vollständig mit Datencheck und Daten schreiben,
 * gegen das Datenmodell `antrag` aus 5.6.
 */
final class AntragPruefungTest extends TestCase
{
    private EngineHarness $h;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
        $this->h->addModel(Models::antrag());
        $this->h->storage->seed('antrag', [
            Models::antragRow('A-1024'),
            Models::antragRow('A-1', betrag: 500),
        ]);
        $this->h->defineJson(DefinitionParserTest::fixture());
        $this->h->define(['id' => 'archivierung', 'startStep' => 'archivieren', 'steps' => ['archivieren' => []]]);
        $this->h->templates->saveTemplate(new Template(
            'antrag-ok',
            'Antrag bestätigt',
            TemplateType::Email,
            'Ihr Antrag {{antrag_id}} ist freigegeben',
            '<p>Betrag: {{antrag_betrag}}</p>',
        ));
    }

    public function testLargeAmountGoesThroughTheReview(): void
    {
        $instance = $this->h->engine->start('antrag-pruefung', ['antrag_id' => 'A-1024']);
        self::assertSame('pruefen', $instance->currentStep);
        self::assertSame('offen', $instance->context['antrag']);
        self::assertTrue($instance->context['antragFound']);

        $instance = $this->h->engine->handleEvent($instance->id, 'submit', ['bemerkung' => 'passt', 'antrag_betrag' => 1]);

        self::assertSame(InstanceStatus::Completed, $instance->status);
        self::assertSame('abschluss', $instance->currentStep);
        self::assertSame(1250, $instance->context['antrag_betrag'], 'Anzeigefeld darf nicht überschrieben werden.');
        self::assertTrue($instance->context['written']);
        self::assertSame(2, $instance->context['writtenCount']);

        $row = $this->h->storage->row('antrag', 'A-1024');
        self::assertSame('freigegeben', $row['status'] ?? null);
        self::assertSame('2026-10-31', $row['geprueft_am'] ?? null);
        self::assertSame("workflow:antrag-pruefung@1#{$instance->id}/speichern", $this->h->storage->lastOrigin?->toString());

        self::assertSame('Ihr Antrag A-1024 ist freigegeben', $this->h->mailer->last()->subject);
        self::assertSame('<p>Betrag: 1250</p>', $this->h->mailer->last()->body);
        self::assertSame('p@example.org', $this->h->mailer->last()->to);
        self::assertCount(2, $this->h->repo->allInstances(), 'archivierung wurde gestartet');
    }

    public function testSmallAmountWaitsADay(): void
    {
        $instance = $this->h->engine->start('antrag-pruefung', ['antrag_id' => 'A-1']);
        self::assertSame('warten', $instance->currentStep);
        self::assertSame(InstanceStatus::WaitingTimer, $instance->status);

        $this->h->clock->advance('+1 day');
        $this->h->engine->advance($this->h->reload($instance->id));

        self::assertSame(InstanceStatus::Completed, $this->h->reload($instance->id)->status);
        self::assertSame('freigegeben', $this->h->storage->row('antrag', 'A-1')['status'] ?? null);
    }

    public function testUnknownModelsAreRejectedWhenSaving(): void
    {
        $json = str_replace('"entity": "antrag"', '"entity": "antraege"', DefinitionParserTest::fixture());

        $this->expectExceptionMessage("Datenmodell 'antraege' ist nicht bekannt.");
        $this->h->defineJson($json, 2);
    }
}
