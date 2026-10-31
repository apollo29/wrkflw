<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Exception\InvalidStateException;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\EngineHarness;

/** «Ein Schritt zurück» (F-56). */
final class GoBackTest extends TestCase
{
    private EngineHarness $h;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
        $this->h->define([
            'id' => 'strecke', 'startStep' => 'angaben',
            'steps' => [
                'angaben' => [
                    'type' => 'interactive',
                    'ui' => ['fields' => [['name' => 'zertifikat']]],
                    'transitions' => [['to' => 'mail', 'event' => 'submit']],
                ],
                'mail' => [
                    'action' => 'send_email',
                    'config' => ['to' => 'a@example.org', 'subject' => 'Eingang', 'body' => 'x'],
                    'transitions' => [['to' => 'upload']],
                ],
                'upload' => [
                    'type' => 'interactive',
                    'ui' => ['back' => true],
                    'transitions' => [['to' => 'ende', 'event' => 'submit']],
                ],
                'ende' => [],
            ],
        ]);
    }

    public function testGoesBackToTheLastVisitedInteractiveStepWithoutRepeatingActions(): void
    {
        $id = $this->h->engine->start('strecke')->id;
        $this->h->engine->handleEvent($id, 'submit', ['zertifikat' => 'ja']);
        self::assertTrue($this->h->engine->canGoBack($this->h->reload($id)));

        $instance = $this->h->engine->goBack($id);

        self::assertSame('angaben', $instance->currentStep);
        self::assertSame(InstanceStatus::WaitingEvent, $instance->status);
        self::assertSame('ja', $instance->context['zertifikat']);
        self::assertCount(1, $this->h->mailer->sent);
        self::assertContains('back', $this->h->repo->historyKinds($id));
    }

    public function testNotAllowedWithoutTheSwitchOrWithoutTarget(): void
    {
        $id = $this->h->engine->start('strecke')->id;
        self::assertFalse($this->h->engine->canGoBack($this->h->reload($id)));

        $this->h->define([
            'id' => 'nur-einer', 'startStep' => 'a',
            'steps' => ['a' => ['type' => 'interactive', 'ui' => ['back' => true], 'transitions' => [['to' => 'b', 'event' => 'submit']]], 'b' => []],
        ]);
        $single = $this->h->engine->start('nur-einer');
        self::assertFalse($this->h->engine->canGoBack($single));

        $this->expectException(InvalidStateException::class);
        $this->h->engine->goBack($id);
    }

    public function testWithoutTargetInTheChildTheChildIsCancelledAndTheParentGoesBack(): void
    {
        $this->h->define([
            'id' => 'upload-kind', 'startStep' => 'hochladen',
            'steps' => ['hochladen' => ['type' => 'interactive', 'ui' => ['back' => true], 'transitions' => [['to' => 'fertig', 'event' => 'submit']]], 'fertig' => []],
        ]);
        $this->h->define([
            'id' => 'eltern', 'startStep' => 'frage',
            'steps' => [
                'frage' => ['type' => 'interactive', 'transitions' => [['to' => 'kind', 'event' => 'submit']]],
                'kind' => ['action' => 'start_workflow', 'config' => ['workflowId' => 'upload-kind', 'waitForCompletion' => true], 'transitions' => [['to' => 'ende']]],
                'ende' => [],
            ],
        ]);
        $parentId = $this->h->engine->start('eltern')->id;
        $this->h->engine->handleEvent($parentId, 'submit');
        $childId = (string) $this->h->reload($parentId)->awaitedChildId();

        self::assertTrue($this->h->engine->canGoBack($this->h->reload($childId)));
        $parent = $this->h->engine->goBack($childId);

        self::assertSame($parentId, $parent->id);
        self::assertSame('frage', $parent->currentStep);
        self::assertSame(InstanceStatus::WaitingEvent, $parent->status);
        self::assertNull($parent->awaitedChildId());
        self::assertSame(InstanceStatus::Cancelled, $this->h->reload($childId)->status);
        // Der Rücksprung weckt den Eltern-Ablauf nicht über das Ende des Kindes.
        self::assertNotContains('subworkflow_done', $this->h->repo->historyKinds($parentId));
    }
}
