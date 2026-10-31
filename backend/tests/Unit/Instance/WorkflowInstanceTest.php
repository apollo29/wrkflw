<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Instance;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Instance\ContextKeys;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;

final class WorkflowInstanceTest extends TestCase
{
    public function testFinishedStates(): void
    {
        $finished = array_filter(InstanceStatus::cases(), static fn (InstanceStatus $s): bool => $s->isFinished());

        self::assertSame(
            [InstanceStatus::Completed, InstanceStatus::Failed, InstanceStatus::Cancelled],
            array_values($finished),
        );
    }

    public function testContextHelpers(): void
    {
        $instance = new WorkflowInstance('i', 'w', 1, 's', InstanceStatus::Running, [
            'a' => 1,
            ContextKeys::PARENT_LINK => ['instanceId' => 'p'],
            ContextKeys::AWAIT_WORKFLOW => 'k',
        ]);
        $instance->mergeContext(['a' => 2, 'b' => 3]);

        self::assertSame(['a' => 2, 'b' => 3], $instance->publicContext());
        self::assertSame('p', $instance->parentId());
        self::assertSame('k', $instance->awaitedChildId());
        self::assertTrue(ContextKeys::isInternal('__x'));
        self::assertFalse(ContextKeys::isInternal('_x'));
    }

    public function testBrokenLinksAreIgnored(): void
    {
        $instance = new WorkflowInstance('i', 'w', 1, 's', InstanceStatus::Running, [
            ContextKeys::PARENT_LINK => 'kaputt',
            ContextKeys::AWAIT_WORKFLOW => 5,
        ]);

        self::assertNull($instance->parentId());
        self::assertNull($instance->awaitedChildId());
    }
}
