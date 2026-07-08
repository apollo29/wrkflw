<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SubWorkflowAction;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Verknuepfte Workflows: ein Schritt stoesst per start_workflow-Action einen
 * anderen Workflow an — entweder feuer-und-vergiss oder mit Warten auf Abschluss.
 */
#[CoversClass(SubWorkflowAction::class)]
#[CoversClass(WorkflowEngine::class)]
final class SubWorkflowTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();

        $actions = new ActionRegistry();
        $actions->register('start_workflow', new SubWorkflowAction(fn (): WorkflowEngine => $this->engine));

        $this->engine = new WorkflowEngine($this->repo, $actions, new SymfonyExpressionEvaluator());
    }

    public function testFireAndForgetStartsChildAndParentContinues(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childAutomatic()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray(
            $this->parent(['workflowId' => 'child', 'waitForCompletion' => false])
        ));

        $parent = $this->engine->start('parent', ['user' => 'mara']);

        // Eltern laeuft sofort durch.
        self::assertSame(WorkflowInstance::COMPLETED, $parent->status);
        // Genau ein Kind wurde gestartet und ist ebenfalls fertig.
        $children = $this->childrenOf($parent->id);
        self::assertCount(1, $children);
        self::assertSame(WorkflowInstance::COMPLETED, $children[0]->status);
        // Kind erbt den Eltern-Kontext.
        self::assertSame('mara', $children[0]->context['user'] ?? null);
        // Referenz im Eltern-Kontext hinterlegt.
        self::assertIsArray($parent->context['startedWorkflow'] ?? null);
    }

    public function testWaitForCompletionWithSynchronousChildContinuesImmediately(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childAutomatic()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray(
            $this->parent(['workflowId' => 'child', 'waitForCompletion' => true])
        ));

        $parent = $this->engine->start('parent', ['user' => 'mara']);

        // Kind ist rein automatisch -> synchron fertig -> Eltern laeuft direkt weiter.
        self::assertSame(WorkflowInstance::COMPLETED, $parent->status);
        self::assertSame('done', $parent->currentStep);
        $sub = $parent->context['subWorkflow'] ?? null;
        self::assertIsArray($sub);
        $status = $sub['status'] ?? null;
        self::assertIsString($status);
        self::assertSame(WorkflowInstance::COMPLETED, $status);
    }

    public function testWaitForCompletionSuspendsUntilChildFinishes(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childInteractive()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray(
            $this->parent(['workflowId' => 'child', 'waitForCompletion' => true])
        ));

        $parent = $this->engine->start('parent', ['user' => 'mara']);

        // Kind haelt an einem interaktiven Schritt -> Eltern wartet.
        self::assertSame(WorkflowInstance::WAITING_EVENT, $parent->status);
        self::assertSame('call_child', $parent->currentStep);

        $child = $this->childrenOf($parent->id)[0];
        self::assertSame(WorkflowInstance::WAITING_EVENT, $child->status);

        // Kind abschliessen -> Eltern wird geweckt und laeuft zu Ende.
        $this->engine->handleEvent($child->id, 'submit', ['ok' => true]);

        // Frisch laden: die Instanzen wurden durch handleEvent mutiert (fuer PHPStan
        // unsichtbar), sonst gilt der vorherige WAITING_EVENT-Typ fort.
        self::assertSame(WorkflowInstance::COMPLETED, $this->reload($child->id)->status);

        $reloaded = $this->reload($parent->id);
        self::assertSame(WorkflowInstance::COMPLETED, $reloaded->status);
        self::assertSame('done', $reloaded->currentStep);
        $sub = $reloaded->context['subWorkflow'] ?? null;
        self::assertIsArray($sub);
        $status = $sub['status'] ?? null;
        self::assertIsString($status);
        self::assertSame(WorkflowInstance::COMPLETED, $status);
    }

    public function testParentCanBranchOnChildResult(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childInteractive()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->parentBranching()));

        $parent = $this->engine->start('parent', []);
        $child = $this->childrenOf($parent->id)[0];

        // Kind liefert grade=gold in den Kontext -> Eltern verzweigt nach 'gold'.
        $this->engine->handleEvent($child->id, 'submit', ['grade' => 'gold']);

        self::assertSame('gold', $parent->currentStep);
        self::assertSame(WorkflowInstance::COMPLETED, $parent->status);
    }

    /**
     * Laedt eine Instanz frisch aus dem Repository (garantiert non-null).
     */
    private function reload(string $id): WorkflowInstance
    {
        $instance = $this->repo->findInstance($id);
        self::assertNotNull($instance);

        return $instance;
    }

    /**
     * @return list<WorkflowInstance>
     */
    private function childrenOf(string $parentId): array
    {
        $children = [];
        foreach ($this->repo->allInstances() as $instance) {
            if ($instance->id !== $parentId) {
                $children[] = $instance;
            }
        }

        return $children;
    }

    /**
     * @return array<string,mixed>
     */
    private function childAutomatic(): array
    {
        return [
            'id' => 'child',
            'startStep' => 'go',
            'steps' => [
                'go' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function childInteractive(): array
    {
        return [
            'id' => 'child',
            'startStep' => 'ask',
            'steps' => [
                'ask' => [
                    'type' => 'interactive',
                    'ui' => ['events' => ['submit']],
                    'transitions' => [['to' => 'end', 'event' => 'submit']],
                ],
                'end' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    private function parent(array $config): array
    {
        return [
            'id' => 'parent',
            'startStep' => 'call_child',
            'steps' => [
                'call_child' => [
                    'type' => 'automatic',
                    'action' => 'start_workflow',
                    'config' => $config,
                    'transitions' => [['to' => 'done']],
                ],
                'done' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parentBranching(): array
    {
        return [
            'id' => 'parent',
            'startStep' => 'call_child',
            'steps' => [
                'call_child' => [
                    'type' => 'automatic',
                    'action' => 'start_workflow',
                    'config' => ['workflowId' => 'child', 'waitForCompletion' => true],
                    'transitions' => [
                        ['to' => 'gold', 'when' => "context['subWorkflow']['context']['grade'] == 'gold'"],
                        ['to' => 'silver'],
                    ],
                ],
                'gold' => ['type' => 'automatic', 'transitions' => []],
                'silver' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }
}
