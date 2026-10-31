<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Contracts\TriggerInterface;
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\ArrayLogger;
use WorkflowEngine\Tests\Support\EngineHarness;
use WorkflowEngine\Tests\Support\InMemoryTriggerLock;
use WorkflowEngine\Trigger\TriggerStart;

final class WorkflowRunnerTest extends TestCase
{
    private EngineHarness $h;

    private InMemoryTriggerLock $lock;

    private ArrayLogger $logger;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
        $this->lock = new InMemoryTriggerLock();
        $this->logger = new ArrayLogger();
        $this->h->define([
            'id' => 'erinnern', 'startStep' => 'warten',
            'inputs' => [['name' => 'person', 'required' => true]],
            'steps' => [
                'warten' => ['type' => 'timer', 'delaySeconds' => 3600, 'transitions' => [['to' => 'ende']]],
                'ende' => [],
            ],
        ]);
    }

    private function runner(): WorkflowRunner
    {
        return new WorkflowRunner($this->h->engine, $this->h->repo, $this->lock, $this->h->clock, $this->logger);
    }

    /**
     * @param list<TriggerStart> $starts
     */
    private static function trigger(array $starts): TriggerInterface
    {
        return new class ($starts) implements TriggerInterface {
            /**
             * @param list<TriggerStart> $starts
             */
            public function __construct(private readonly array $starts)
            {
            }

            public function poll(): array
            {
                return $this->starts;
            }
        };
    }

    public function testWakesDueInstancesOnly(): void
    {
        $due = $this->h->engine->start('erinnern', ['person' => 'a']);
        $this->h->clock->advance('+30 minutes');
        $later = $this->h->engine->start('erinnern', ['person' => 'b']);
        $this->h->clock->advance('+31 minutes');

        $stats = $this->runner()->tick();

        self::assertSame(1, $stats->woken);
        self::assertSame(InstanceStatus::Completed, $this->h->reload($due->id)->status);
        self::assertSame(InstanceStatus::WaitingTimer, $this->h->reload($later->id)->status);
    }

    public function testATriggerStartsOncePerSubjectWithinItsLockPeriod(): void
    {
        $runner = $this->runner();
        $runner->addTrigger(self::trigger([
            new TriggerStart('erinnern', ['person' => 'P-1'], 'person', 'P-1'),
            new TriggerStart('erinnern', ['person' => 'P-2'], 'person', 'P-2'),
        ]), 'frist', lockSeconds: 7 * 86400);

        self::assertSame(2, $runner->tick()->started);
        self::assertSame(0, $runner->tick()->started, 'gesperrt');

        $this->h->clock->advance('+8 days');
        self::assertSame(2, $runner->tick()->started);
    }

    public function testFailuresAreCountedAndDoNotStopTheTick(): void
    {
        $runner = $this->runner();
        $runner->addTrigger(self::trigger([
            new TriggerStart('erinnern', [], 'person', 'P-1'),
            new TriggerStart('gibt-es-nicht', []),
            new TriggerStart('erinnern', ['person' => 'P-2'], 'person', 'P-2'),
        ]), 'frist', lockSeconds: 3600);

        $stats = $runner->tick();

        self::assertSame(1, $stats->started);
        self::assertSame(2, $stats->errors);
        self::assertCount(2, array_filter($this->logger->records, static fn (array $r): bool => $r['level'] === 'error'));
        self::assertTrue($this->lock->acquire('frist', 'person:P-1', $this->h->clock->now(), $this->h->clock->now()), 'Sperre nach gescheitertem Start wieder frei');
    }

    public function testATriggerWithoutLockStartsEveryTime(): void
    {
        $runner = $this->runner();
        $runner->addTrigger(self::trigger([new TriggerStart('erinnern', ['person' => 'P-1'])]), 'ohne-sperre');

        $runner->tick();
        $runner->tick();

        self::assertCount(2, $this->h->repo->allInstances());
    }
}
