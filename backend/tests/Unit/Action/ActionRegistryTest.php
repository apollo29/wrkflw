<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ActionResult;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\ArrayMailer;

final class ActionRegistryTest extends TestCase
{
    public function testUnknownKeysThrow(): void
    {
        $this->expectException(NotFoundException::class);
        (new ActionRegistry())->get('weg');
    }

    public function testCatalogUsesReadableLabelsAndFallsBackToTheKey(): void
    {
        $registry = new ActionRegistry();
        $registry->register('send_email', new SendEmailAction(new ArrayMailer()));
        $registry->register('mark_vip', new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): ActionResult
            {
                return ActionResult::of(['vip' => true]);
            }
        });

        $catalog = $registry->catalog();

        self::assertSame(['send_email', 'mark_vip'], $registry->keys());
        self::assertSame('E-Mail senden', $catalog[0]['label']);
        self::assertSame(['key' => 'mark_vip', 'label' => 'mark_vip', 'fields' => []], $catalog[1]);
        self::assertTrue($registry->has('mark_vip'));
    }
}
