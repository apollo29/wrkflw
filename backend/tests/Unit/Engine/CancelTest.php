<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Exception\InvalidStateException;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\EngineHarness;

/**
 * Durchlauf von Hand abbrechen (offener Punkt 4). Entscheid: ein abgebrochenes Kind
 * weckt den wartenden Eltern-Ablauf mit dem Endzustand «abgebrochen»; ein
 * abgebrochener Eltern-Ablauf nimmt sein erwartetes Kind mit.
 */
final class CancelTest extends TestCase
{
    private EngineHarness $h;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
        $this->h->define([
            'id' => 'kind', 'startStep' => 'eingabe',
            'steps' => ['eingabe' => ['type' => 'interactive', 'transitions' => [['to' => 'ende', 'event' => 'submit']]], 'ende' => []],
        ]);
        $this->h->define([
            'id' => 'eltern', 'startStep' => 'starten',
            'steps' => [
                'starten' => [
                    'action' => 'start_workflow',
                    'config' => ['workflowId' => 'kind', 'waitForCompletion' => true],
                    'transitions' => [
                        ['to' => 'abgebrochen', 'when' => "context['subWorkflow']['status'] == 'cancelled'"],
                        ['to' => 'weiter'],
                    ],
                ],
                'abgebrochen' => [],
                'weiter' => [],
            ],
        ]);
    }

    private function awaitedChild(string $parentId): string
    {
        return (string) $this->h->reload($parentId)->awaitedChildId();
    }

    public function testAWaitingInstanceCanBeCancelled(): void
    {
        $this->h->define([
            'id' => 'timer', 'startStep' => 't',
            'steps' => ['t' => ['type' => 'timer', 'delaySeconds' => 60, 'transitions' => [['to' => 'e']]], 'e' => []],
        ]);
        $id = $this->h->engine->start('timer')->id;

        $instance = $this->h->engine->cancel($id);

        self::assertSame(InstanceStatus::Cancelled, $instance->status);
        self::assertNull($instance->wakeAt);
        self::assertTrue($this->h->reload($id)->isFinished());
        $last = $this->h->repo->lastHistory($id);
        self::assertSame(HistoryKind::Cancelled, $last->kind);
        self::assertSame(['reason' => 'manual'], $last->detail);
    }

    public function testFinishedOrUnknownInstancesCannotBeCancelled(): void
    {
        $id = $this->h->engine->start('kind')->id;
        $this->h->engine->handleEvent($id, 'submit');

        try {
            $this->h->engine->cancel($id);
            self::fail('Beendeter Durchlauf abgebrochen.');
        } catch (InvalidStateException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(NotFoundException::class);
        $this->h->engine->cancel('nope');
    }

    public function testCancellingTheChildResumesTheParentWithStatusCancelled(): void
    {
        $parentId = $this->h->engine->start('eltern')->id;

        $this->h->engine->cancel($this->awaitedChild($parentId));

        $parent = $this->h->reload($parentId);
        self::assertSame(InstanceStatus::Completed, $parent->status);
        self::assertSame('abgebrochen', $parent->currentStep);
    }

    public function testCancellingTheParentCancelsTheAwaitedChildToo(): void
    {
        $parentId = $this->h->engine->start('eltern')->id;
        $childId = $this->awaitedChild($parentId);

        $this->h->engine->cancel($parentId);

        $parent = $this->h->reload($parentId);
        $child = $this->h->reload($childId);
        self::assertSame(InstanceStatus::Cancelled, $parent->status);
        self::assertSame('starten', $parent->currentStep);
        self::assertSame(InstanceStatus::Cancelled, $child->status);
        self::assertSame(['reason' => 'parent_cancelled'], $this->h->repo->lastHistory($childId)->detail);
    }

    public function testFireAndForgetChildrenKeepRunning(): void
    {
        $this->h->define([
            'id' => 'starter', 'startStep' => 'a',
            'steps' => [
                'a' => ['action' => 'start_workflow', 'config' => ['workflowId' => 'kind'], 'transitions' => [['to' => 'b']]],
                'b' => ['type' => 'interactive', 'transitions' => [['to' => 'c', 'event' => 'submit']]],
                'c' => [],
            ],
        ]);
        $parent = $this->h->engine->start('starter');
        $started = $parent->context['startedWorkflow'];
        self::assertIsArray($started);
        self::assertIsString($started['id']);

        $this->h->engine->cancel($parent->id);

        self::assertSame(InstanceStatus::WaitingEvent, $this->h->reload($started['id'])->status);
    }
}
