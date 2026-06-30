<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Härtung: Retry/Backoff, Idempotenz und Versions-Pinning.
 */
#[CoversClass(WorkflowEngine::class)]
final class WorkflowEngineHardeningTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private ActionRegistry $actions;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->actions = new ActionRegistry();
    }

    private function engine(int $maxAttempts = 3, int $baseDelay = 1): WorkflowEngine
    {
        return new WorkflowEngine(
            $this->repo,
            $this->actions,
            new SymfonyExpressionEvaluator(),
            maxAttempts: $maxAttempts,
            baseRetryDelaySeconds: $baseDelay,
        );
    }

    private function addAutomatic(string $id, string $action): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => $id,
            'startStep' => 'go',
            'steps' => [
                'go' => ['type' => 'automatic', 'action' => $action, 'transitions' => [['to' => 'done']]],
                'done' => ['type' => 'automatic'],
            ],
        ]));
    }

    public function testFailingActionRetriesWithBackoffThenFails(): void
    {
        $this->addAutomatic('boom-flow', 'boom');
        $this->actions->register('boom', $this->throwingAction());
        $engine = $this->engine(maxAttempts: 3);

        $instance = $engine->start('boom-flow');
        self::assertSame(WorkflowInstance::WAITING_TIMER, $instance->status);
        self::assertSame(1, $instance->attempts);
        self::assertNotNull($instance->wakeAt);

        $instance->wakeAt = (new \DateTimeImmutable())->modify('-1 second');
        $engine->advance($instance);
        self::assertSame(WorkflowInstance::WAITING_TIMER, $instance->status);
        self::assertSame(2, $instance->attempts);

        $instance->wakeAt = (new \DateTimeImmutable())->modify('-1 second');
        $engine->advance($instance);
        self::assertSame(WorkflowInstance::FAILED, $instance->status);
        self::assertSame(3, $instance->attempts);
        self::assertContains('retry', $this->repo->historyKinds());
    }

    public function testActionSucceedsAfterRetryAndResetsAttempts(): void
    {
        $this->addAutomatic('flaky-flow', 'flaky');
        $this->actions->register('flaky', $this->flakyAction(1)); // 1x Fehler, dann Erfolg
        $engine = $this->engine(maxAttempts: 3);

        $instance = $engine->start('flaky-flow');
        self::assertSame(WorkflowInstance::WAITING_TIMER, $instance->status);
        self::assertSame(1, $instance->attempts);

        $instance->wakeAt = (new \DateTimeImmutable())->modify('-1 second');
        $engine->advance($instance);

        self::assertSame(WorkflowInstance::COMPLETED, $instance->status);
        self::assertSame(0, $instance->attempts);
    }

    public function testDuplicateEventIsIdempotent(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'counter-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => ['type' => 'interactive', 'transitions' => [['event' => 'inc', 'to' => 'bump']]],
                'bump' => ['type' => 'automatic', 'action' => 'bump', 'transitions' => [['to' => 'wait']]],
            ],
        ]));
        $this->actions->register('bump', $this->bumpAction());
        $engine = $this->engine();

        $instance = $engine->start('counter-flow', ['count' => 0]);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $instance->status);

        $engine->handleEvent($instance->id, 'inc', [], 'key-1');
        self::assertSame(1, $instance->context['count']);

        // Gleicher Idempotenz-Key -> No-op.
        $engine->handleEvent($instance->id, 'inc', [], 'key-1');
        self::assertSame(1, $instance->context['count']);

        // Anderer Key -> wird verarbeitet.
        $engine->handleEvent($instance->id, 'inc', [], 'key-2');
        self::assertSame(2, $instance->context['count']);

        self::assertContains('event_duplicate', $this->repo->historyKinds());
    }

    public function testAppliedEventIdsAreCappedToAvoidUnboundedGrowth(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'counter-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => ['type' => 'interactive', 'transitions' => [['event' => 'inc', 'to' => 'bump']]],
                'bump' => ['type' => 'automatic', 'action' => 'bump', 'transitions' => [['to' => 'wait']]],
            ],
        ]));
        $this->actions->register('bump', $this->bumpAction());
        $engine = $this->engine();

        $instance = $engine->start('counter-flow', ['count' => 0]);
        for ($i = 0; $i < 60; $i++) {
            $engine->handleEvent($instance->id, 'inc', [], "key-{$i}");
        }

        // Alle 60 eindeutigen Events wurden verarbeitet ...
        self::assertSame(60, $instance->context['count']);
        // ... aber die Liste der Idempotenz-Keys ist gedeckelt.
        $applied = $instance->context['__appliedEventIds'];
        self::assertIsArray($applied);
        self::assertCount(50, $applied);
    }

    public function testRunningInstanceKeepsItsDefinitionVersion(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'version' => 1,
            'startStep' => 'a',
            'steps' => [
                'a' => ['type' => 'interactive', 'transitions' => [['event' => 'go', 'to' => 'one']]],
                'one' => ['type' => 'automatic'],
            ],
        ]));
        $engine = $this->engine();
        $instance = $engine->start('flow');
        self::assertSame(1, $instance->definitionVersion);

        // Neue Version wird aktiv, nachdem die Instanz bereits laeuft.
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'version' => 2,
            'startStep' => 'a',
            'steps' => [
                'a' => ['type' => 'interactive', 'transitions' => [['event' => 'go', 'to' => 'two']]],
                'two' => ['type' => 'automatic'],
            ],
        ]));

        $engine->handleEvent($instance->id, 'go');

        // Die laufende Instanz folgt weiterhin v1 (Ziel 'one', nicht 'two').
        self::assertSame('one', $instance->currentStep);
        self::assertSame(1, $instance->definitionVersion);
    }

    // ---------------------------------------------------------------- Doubles

    private function throwingAction(): ActionInterface
    {
        return new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                throw new \RuntimeException('always fails');
            }
        };
    }

    private function flakyAction(int $failTimes): ActionInterface
    {
        return new class ($failTimes) implements ActionInterface {
            private int $calls = 0;

            public function __construct(private readonly int $failTimes)
            {
            }

            public function execute(WorkflowInstance $instance, Step $step): array
            {
                $this->calls++;
                if ($this->calls <= $this->failTimes) {
                    throw new \RuntimeException('transient');
                }

                return [];
            }
        };
    }

    private function bumpAction(): ActionInterface
    {
        return new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                $current = $instance->context['count'] ?? 0;
                $value = is_numeric($current) ? (int) $current : 0;

                return ['count' => $value + 1];
            }
        };
    }
}
