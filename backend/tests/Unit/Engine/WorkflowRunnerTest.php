<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\TriggerInterface;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

#[CoversClass(WorkflowRunner::class)]
final class WorkflowRunnerTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private WorkflowRunner $runner;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'timer-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => ['type' => 'timer', 'delaySeconds' => 60, 'transitions' => [['to' => 'done']]],
                'done' => ['type' => 'automatic'],
            ],
        ]));

        $engine = new WorkflowEngine($this->repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
        $this->runner = new WorkflowRunner($engine, $this->repo);
    }

    private function dueTimerInstance(string $id, string $definitionId = 'timer-flow'): WorkflowInstance
    {
        return new WorkflowInstance(
            id: $id,
            definitionId: $definitionId,
            definitionVersion: 1,
            currentStep: 'wait',
            status: WorkflowInstance::WAITING_TIMER,
            wakeAt: (new \DateTimeImmutable())->modify('-1 minute'),
        );
    }

    public function testTickAdvancesDueTimerInstance(): void
    {
        $this->repo->saveInstance($this->dueTimerInstance('t1'));

        $stats = $this->runner->tick();

        self::assertSame(1, $stats['woken']);
        $loaded = $this->repo->findInstance('t1');
        self::assertNotNull($loaded);
        self::assertSame(WorkflowInstance::COMPLETED, $loaded->status);
    }

    public function testTickIgnoresInstancesNotYetDue(): void
    {
        $future = $this->dueTimerInstance('t2');
        $future->wakeAt = (new \DateTimeImmutable())->modify('+1 hour');
        $this->repo->saveInstance($future);

        $stats = $this->runner->tick();

        self::assertSame(0, $stats['woken']);
        $loaded = $this->repo->findInstance('t2');
        self::assertNotNull($loaded);
        self::assertSame(WorkflowInstance::WAITING_TIMER, $loaded->status);
    }

    public function testTickDoesNotDoubleProcess(): void
    {
        $this->repo->saveInstance($this->dueTimerInstance('t3'));

        $first = $this->runner->tick();
        $second = $this->runner->tick();

        self::assertSame(1, $first['woken']);
        self::assertSame(0, $second['woken']);
    }

    public function testTickStartsInstancesFromTrigger(): void
    {
        $trigger = new class () implements TriggerInterface {
            public function poll(): array
            {
                return [[
                    'definition' => 'timer-flow',
                    'context' => ['source' => 'trigger'],
                    'subjectType' => 'invoice',
                    'subjectId' => '99',
                ]];
            }
        };
        $this->runner->addTrigger($trigger);

        $stats = $this->runner->tick();

        self::assertSame(1, $stats['started']);
        $started = array_filter(
            $this->repo->allInstances(),
            static fn (WorkflowInstance $i): bool => $i->definitionId === 'timer-flow'
                && ($i->context['source'] ?? null) === 'trigger',
        );
        self::assertCount(1, $started);
    }

    public function testTickCountsErrorsWithoutAborting(): void
    {
        // Instanz verweist auf eine unbekannte Definition -> advance wirft -> Fehler gezaehlt.
        $this->repo->saveInstance($this->dueTimerInstance('bad', 'ghost-def'));
        $this->repo->saveInstance($this->dueTimerInstance('good'));

        $stats = $this->runner->tick();

        self::assertSame(1, $stats['errors']);
        self::assertSame(1, $stats['woken']);
        $good = $this->repo->findInstance('good');
        self::assertNotNull($good);
        self::assertSame(WorkflowInstance::COMPLETED, $good->status);
    }
}
