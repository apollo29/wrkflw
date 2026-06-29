<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\WorkflowInstance;

#[CoversClass(ActionRegistry::class)]
final class ActionRegistryTest extends TestCase
{
    public function testRegisterGetAndHas(): void
    {
        $registry = new ActionRegistry();
        $action = $this->dummyAction();

        self::assertFalse($registry->has('demo'));

        $registry->register('demo', $action);

        self::assertTrue($registry->has('demo'));
        self::assertSame($action, $registry->get('demo'));
    }

    public function testUnknownActionKeyThrows(): void
    {
        $registry = new ActionRegistry();

        $this->expectException(WorkflowException::class);
        $registry->get('nope');
    }

    private function dummyAction(): ActionInterface
    {
        return new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                return [];
            }
        };
    }
}
