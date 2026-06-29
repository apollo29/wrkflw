<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Instance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Instance\WorkflowInstance;

#[CoversClass(WorkflowInstance::class)]
final class WorkflowInstanceTest extends TestCase
{
    private function instance(): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'i1',
            definitionId: 'onboarding',
            definitionVersion: 1,
            currentStep: 'start',
            status: WorkflowInstance::RUNNING,
            context: ['name' => 'Mara'],
        );
    }

    public function testIsFinishedReflectsTerminalStates(): void
    {
        $i = $this->instance();
        self::assertFalse($i->isFinished());

        $i->status = WorkflowInstance::COMPLETED;
        self::assertTrue($i->isFinished());

        $i->status = WorkflowInstance::FAILED;
        self::assertTrue($i->isFinished());
    }

    public function testSetWritesContextValue(): void
    {
        $i = $this->instance();
        $i->set('phone', '123');

        self::assertSame('123', $i->context['phone']);
    }

    public function testMergeContextReplacesAndAdds(): void
    {
        $i = $this->instance();
        $i->mergeContext(['name' => 'Lea', 'vip' => true]);

        self::assertSame('Lea', $i->context['name']);
        self::assertTrue($i->context['vip']);
    }
}
