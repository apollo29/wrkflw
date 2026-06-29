<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;
use WorkflowEngine\Tests\Support\MarkVipAction;

/**
 * Demonstriert eine Host-App-Action (MarkVipAction), die den Kontext veraendert,
 * und dass die Engine ihre Rueckgabewerte mergt und in Transitionen nutzen kann.
 */
#[CoversClass(MarkVipAction::class)]
final class ExampleHostActionTest extends TestCase
{
    public function testActionDerivesValueFromContext(): void
    {
        $action = new MarkVipAction();
        $instance = new WorkflowInstance(
            id: 'i',
            definitionId: 'd',
            definitionVersion: 1,
            currentStep: 'classify',
            status: WorkflowInstance::RUNNING,
            context: ['amount' => 1500],
        );

        $result = $action->execute($instance, Step::fromArray('classify', ['type' => 'automatic']));

        self::assertTrue($result['vip']);
    }

    public function testEngineMergesResultAndBranchesOnIt(): void
    {
        $repo = new InMemoryWorkflowRepository();
        $repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'vip-flow',
            'startStep' => 'classify',
            'steps' => [
                'classify' => [
                    'type' => 'automatic',
                    'action' => 'mark_vip',
                    'transitions' => [
                        ['to' => 'vip', 'when' => "context['vip'] == true"],
                        ['to' => 'standard'],
                    ],
                ],
                'vip' => ['type' => 'automatic'],
                'standard' => ['type' => 'automatic'],
            ],
        ]));
        $actions = new ActionRegistry();
        $actions->register('mark_vip', new MarkVipAction());
        $engine = new WorkflowEngine($repo, $actions, new SymfonyExpressionEvaluator());

        $vip = $engine->start('vip-flow', ['amount' => 5000]);
        self::assertTrue($vip->context['vip']);
        self::assertSame('vip', $vip->currentStep);

        $standard = $engine->start('vip-flow', ['amount' => 10]);
        self::assertFalse($standard->context['vip']);
        self::assertSame('standard', $standard->currentStep);
    }
}
