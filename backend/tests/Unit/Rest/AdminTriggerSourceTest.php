<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Rest\AdminTrigger;
use WorkflowEngine\Rest\AdminTriggerSource;
use WorkflowEngine\Tests\Support\EngineHarness;
use WorkflowEngine\Tests\Support\InMemoryDataSourceStore;
use WorkflowEngine\Tests\Support\InMemoryTriggerLock;
use WorkflowEngine\Tests\Support\Models;

/** Admin-Trigger im Hintergrundlauf: Takt und Sperrfrist (F-142, F-143). */
final class AdminTriggerSourceTest extends TestCase
{
    public function testDueTriggersStartOncePerRecordWithinTheLockPeriod(): void
    {
        $h = new EngineHarness();
        $h->addModel(Models::antrag());
        $h->storage->seed('antrag', [Models::antragRow('A-1'), Models::antragRow('A-2', betrag: 300)]);
        $h->define([
            'id' => 'pruefen', 'startStep' => 'a',
            'inputs' => [['name' => 'antrag_id', 'required' => true]],
            'steps' => ['a' => ['type' => 'interactive', 'transitions' => [['to' => 'b', 'event' => 'submit']]], 'b' => []],
        ]);
        $store = new InMemoryDataSourceStore();
        $store->saveTrigger(new AdminTrigger('t1', 'Gross', 'antrag', [new Condition('betrag', ConditionOperator::Gt, 1000)], 'pruefen', ['antrag_id' => 'id'], intervalMinutes: 15, lockDays: 7, active: true));
        $store->saveTrigger(new AdminTrigger('t2', 'Aus', 'antrag', [], 'pruefen', ['antrag_id' => 'id']));

        $runner = new WorkflowRunner($h->engine, $h->repo, new InMemoryTriggerLock(), $h->clock);
        $runner->addTriggerSource(new AdminTriggerSource($store, $h->data));

        self::assertSame(1, $runner->tick()->started);
        self::assertSame(0, $runner->tick()->started, 'Takt noch nicht erreicht');

        $h->clock->advance('+15 minutes');
        $stats = $runner->tick();
        self::assertSame([0, 1], [$stats->started, $stats->locked], 'Sperrfrist je Datensatz');

        $h->clock->advance('+8 days');
        self::assertSame(1, $runner->tick()->started);
        self::assertSame('antrag', $h->repo->allInstances()[0]->subjectType);
        self::assertSame('A-1', $h->repo->allInstances()[0]->context['antrag_id']);
    }
}
