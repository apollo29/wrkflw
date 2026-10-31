<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\EngineHarness;
use WorkflowEngine\Tests\Unit\Definition\DefinitionParserTest;

/** Der Beispielablauf aus Abschnitt 9.13 (ohne Datenschritte, die folgen in Phase 2). */
final class AntragPruefungTest extends TestCase
{
    private EngineHarness $h;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
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
        $instance = $this->h->engine->start('antrag-pruefung', ['antrag_id' => 'A-1024', 'antrag_betrag' => 1250, 'antrag_mail' => 'p@example.org']);
        self::assertSame('pruefen', $instance->currentStep);

        $instance = $this->h->engine->handleEvent($instance->id, 'submit', ['bemerkung' => 'passt', 'antrag_betrag' => 1]);

        self::assertSame(InstanceStatus::Completed, $instance->status);
        self::assertSame('abschluss', $instance->currentStep);
        self::assertSame(1250, $instance->context['antrag_betrag'], 'Anzeigefeld darf nicht überschrieben werden.');
        self::assertCount(1, $this->h->mailer->sent);
        self::assertSame('Ihr Antrag A-1024 ist freigegeben', $this->h->mailer->sent[0]->subject);
        self::assertSame('p@example.org', $this->h->mailer->sent[0]->to);
        self::assertCount(2, $this->h->repo->allInstances(), 'archivierung wurde gestartet');
    }

    public function testSmallAmountWaitsADay(): void
    {
        $instance = $this->h->engine->start('antrag-pruefung', ['antrag_id' => 'A-1', 'antrag_betrag' => 500, 'antrag_mail' => 'p@example.org']);
        self::assertSame('warten', $instance->currentStep);
        self::assertSame(InstanceStatus::WaitingTimer, $instance->status);

        $this->h->clock->advance('+1 day');
        $this->h->engine->advance($this->h->reload($instance->id));

        self::assertSame(InstanceStatus::Completed, $this->h->reload($instance->id)->status);
        self::assertCount(1, $this->h->mailer->sent);
    }
}
