<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Tests\Support\FrozenClock;

final class PlaceholderRendererTest extends TestCase
{
    private PlaceholderRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PlaceholderRenderer(new FrozenClock('2026-10-31 14:32:05'));
    }

    public function testReplacesContextValues(): void
    {
        self::assertSame(
            'Antrag A-1024 über 1250',
            $this->renderer->render('Antrag {{antrag_id}} über {{ antrag_betrag }}', ['antrag_id' => 'A-1024', 'antrag_betrag' => 1250]),
        );
    }

    public function testDottedPathsReachNestedValuesButExactKeysWin(): void
    {
        $context = ['subWorkflow' => ['status' => 'completed'], 'a.b' => 'exakt', 'a' => ['b' => 'verschachtelt']];

        self::assertSame('completed', $this->renderer->render('{{subWorkflow.status}}', $context));
        self::assertSame('exakt', $this->renderer->render('{{a.b}}', $context));
    }

    public function testUnknownNonScalarAndInternalValuesRenderEmpty(): void
    {
        $context = ['liste' => [1, 2], '__parent' => ['instanceId' => 'x'], 'leer' => null, 'ja' => true];

        self::assertSame('[||||1]', $this->renderer->render('[{{fehlt}}|{{liste}}|{{__parent.instanceId}}|{{leer}}|{{ja}}]', $context));
    }

    public function testBuiltInTimeValues(): void
    {
        self::assertSame(
            '2026-10-31 | 2026-10-31T14:32:05+01:00 | 2026',
            $this->renderer->render('{{now}} | {{now.datetime}} | {{now.year}}', []),
        );
    }

    public function testAContextValueNamedNowIsNotOverridden(): void
    {
        self::assertSame('2026-10-31', $this->renderer->render('{{now}}', ['now' => 'nie']));
    }

    public function testIsPlaceholderOnly(): void
    {
        self::assertSame('now', PlaceholderRenderer::singlePlaceholder('{{ now }}'));
        self::assertNull(PlaceholderRenderer::singlePlaceholder('am {{now}}'));
    }
}
