<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\CheckDataAction;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryDataProvider;

#[CoversClass(CheckDataAction::class)]
final class CheckDataActionTest extends TestCase
{
    /** @param array<string,mixed> $context */
    private function instance(array $context): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'i1',
            definitionId: 'flow',
            definitionVersion: 1,
            currentStep: 'check',
            status: WorkflowInstance::RUNNING,
            context: $context,
        );
    }

    /** @param array<string,mixed> $config */
    private function step(array $config): Step
    {
        return Step::fromArray('check', ['type' => 'automatic', 'action' => 'check_data', 'config' => $config]);
    }

    public function testReadsFieldIntoContextUnderAlias(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('order', '42', ['id' => 42, 'status' => 'paid', 'total' => 99]);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['orderId' => 42]),
            $this->step(['entity' => 'order', 'id' => '{{orderId}}', 'field' => 'status', 'as' => 'orderStatus']),
        );

        self::assertSame('paid', $result['orderStatus']);
        self::assertTrue($result['orderStatusFound']);
    }

    public function testMissingRowYieldsNullAndNotFound(): void
    {
        $data = new InMemoryDataProvider();
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['orderId' => 999]),
            $this->step(['entity' => 'order', 'id' => '{{orderId}}', 'field' => 'status', 'as' => 'orderStatus']),
        );

        self::assertNull($result['orderStatus']);
        self::assertFalse($result['orderStatusFound']);
    }

    public function testDefaultAliasWhenNotConfigured(): void
    {
        $data = new InMemoryDataProvider();
        $data->set('user', '7', ['id' => 7, 'vip' => true]);
        $action = new CheckDataAction($data);

        $result = $action->execute(
            $this->instance(['uid' => 7]),
            $this->step(['entity' => 'user', 'id' => '{{uid}}', 'field' => 'vip']),
        );

        self::assertTrue($result['checkedValue']);
        self::assertTrue($result['checkedValueFound']);
    }
}
