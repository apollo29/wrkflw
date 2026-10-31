<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\EngineHarness;

/** Verknüpfte Abläufe (F-39). */
final class SubWorkflowTest extends TestCase
{
    private EngineHarness $h;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
        $this->h->define([
            'id' => 'kind', 'startStep' => 'eingabe',
            'steps' => [
                'eingabe' => [
                    'type' => 'interactive',
                    'ui' => ['fields' => [['name' => 'antwort']]],
                    'transitions' => [['to' => 'ok', 'event' => 'submit'], ['to' => 'kaputt', 'event' => 'fehler']],
                ],
                'ok' => [],
                'kaputt' => ['action' => 'send_email', 'config' => ['to' => '']],
            ],
        ]);
        $this->h->define([
            'id' => 'sofort', 'startStep' => 'fertig', 'steps' => ['fertig' => []],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parent(string $child, bool $wait): array
    {
        return [
            'id' => 'eltern', 'startStep' => 'starten',
            'steps' => [
                'starten' => [
                    'action' => 'start_workflow',
                    'config' => ['workflowId' => $child, 'waitForCompletion' => $wait],
                    'transitions' => [
                        ['to' => 'gescheitert', 'when' => "context['subWorkflow'] != null and context['subWorkflow']['status'] != 'completed'"],
                        ['to' => 'weiter'],
                    ],
                ],
                'gescheitert' => [],
                'weiter' => [],
            ],
        ];
    }

    private function childOf(string $parentId): string
    {
        foreach ($this->h->repo->allInstances() as $instance) {
            if ($instance->parentId() === $parentId) {
                return $instance->id;
            }
        }
        self::fail('Kein Kind gefunden.');
    }

    public function testFireAndForgetContinuesImmediately(): void
    {
        $this->h->define(self::parent('kind', wait: false));

        $parent = $this->h->engine->start('eltern', ['antrag_id' => 'A-1']);

        self::assertSame(InstanceStatus::Completed, $parent->status);
        self::assertSame('weiter', $parent->currentStep);
        $started = $parent->context['startedWorkflow'];
        self::assertIsArray($started);
        self::assertIsString($started['id']);
        $child = $this->h->reload($started['id']);
        self::assertSame(InstanceStatus::WaitingEvent, $child->status);
        self::assertSame('A-1', $child->context['antrag_id']);
        self::assertNull($child->parentId());
    }

    public function testWaitingParentResumesWithTheChildResult(): void
    {
        $this->h->define(self::parent('kind', wait: true));
        $parent = $this->h->engine->start('eltern');
        self::assertSame(InstanceStatus::WaitingEvent, $parent->status);
        self::assertContains('wait_subworkflow', $this->h->repo->historyKinds($parent->id));

        $this->h->engine->handleEvent($this->childOf($parent->id), 'submit', ['antwort' => 'ja']);

        $parent = $this->h->reload($parent->id);
        self::assertSame(InstanceStatus::Completed, $parent->status);
        self::assertSame('weiter', $parent->currentStep);
        $result = $parent->context['subWorkflow'];
        self::assertIsArray($result);
        self::assertSame('completed', $result['status']);
        self::assertSame(['antwort' => 'ja'], array_intersect_key((array) $result['context'], ['antwort' => 1]));
        self::assertArrayNotHasKey('__parent', (array) $result['context']);
        self::assertArrayNotHasKey('__awaitWorkflow', $parent->context);
    }

    public function testAFailedChildLetsTheParentBranch(): void
    {
        $this->h->define(self::parent('kind', wait: true));
        $parent = $this->h->engine->start('eltern');
        $childId = $this->childOf($parent->id);

        $this->h->engine->handleEvent($childId, 'fehler');
        foreach (['+60 seconds', '+120 seconds'] as $wait) {
            $this->h->clock->advance($wait);
            $this->h->engine->advance($this->h->reload($childId));
        }

        self::assertSame(InstanceStatus::Failed, $this->h->reload($childId)->status);
        $parent = $this->h->reload($parent->id);
        self::assertSame('gescheitert', $parent->currentStep);
        self::assertSame(InstanceStatus::Completed, $parent->status);
    }

    public function testAChildThatFinishesSynchronouslyNeedsNoWaiting(): void
    {
        $this->h->define(self::parent('sofort', wait: true));

        $parent = $this->h->engine->start('eltern');

        self::assertSame(InstanceStatus::Completed, $parent->status);
        self::assertSame('weiter', $parent->currentStep);
    }

    public function testEventsOnAParentWaitingForAChildAreRejected(): void
    {
        $this->h->define(self::parent('kind', wait: true));
        $parent = $this->h->engine->start('eltern');

        $this->expectException(\WorkflowEngine\Exception\InvalidStateException::class);
        $this->h->engine->handleEvent($parent->id, 'submit');
    }
}
