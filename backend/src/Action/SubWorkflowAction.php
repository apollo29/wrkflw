<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ActionResult;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\WorkflowStarterInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\ContextKeys;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Startet einen anderen Ablauf (F-39) und läuft entweder sofort weiter oder wartet auf
 * dessen Ende. Das Kind erhält den öffentlichen Kontext des Eltern-Ablaufs.
 *
 * Ergebnis im Kontext: `startedWorkflow` (ID und Definition des Kindes) und beim
 * Warten nach dem Ende `subWorkflow` (Status und öffentlicher Kontext des Kindes).
 */
final readonly class SubWorkflowAction implements ActionInterface, ConfigurableActionInterface
{
    private const int MAX_DEPTH = 20;

    /**
     * @param \Closure(): WorkflowStarterInterface $starter verzögert, weil die Engine selbst
     *                                                     der Starter ist und erst danach entsteht
     */
    public function __construct(private \Closure $starter)
    {
    }

    public function label(): string
    {
        return 'Workflow starten';
    }

    public function configSchema(): array
    {
        return [
            ['name' => 'workflowId', 'label' => 'Workflow', 'type' => 'workflow-ref'],
            ['name' => 'waitForCompletion', 'label' => 'Auf Abschluss warten', 'type' => 'boolean'],
        ];
    }

    public function execute(WorkflowInstance $instance, Step $step): ActionResult
    {
        $definitionId = $step->config['workflowId'] ?? null;
        if (!is_string($definitionId) || $definitionId === '') {
            throw new WorkflowException("Schritt '{$step->name}': es ist kein Workflow gewählt.");
        }

        $depth = $instance->context[ContextKeys::SUB_DEPTH] ?? 0;
        $depth = is_int($depth) ? $depth : 0;
        if ($depth >= self::MAX_DEPTH) {
            throw new WorkflowException("Schritt '{$step->name}': maximale Verschachtelungstiefe verknüpfter Abläufe erreicht.");
        }

        $wait = filter_var($step->config['waitForCompletion'] ?? false, FILTER_VALIDATE_BOOL);

        $childContext = $instance->publicContext();
        $childContext[ContextKeys::SUB_DEPTH] = $depth + 1;
        if ($wait) {
            $childContext[ContextKeys::PARENT_LINK] = ['instanceId' => $instance->id];
        }

        $child = ($this->starter)()->start($definitionId, $childContext, $instance->subjectType, $instance->subjectId);
        $reference = ['startedWorkflow' => ['id' => $child->id, 'definitionId' => $child->definitionId]];

        if (!$wait) {
            return ActionResult::of($reference);
        }

        if ($child->isFinished()) {
            return ActionResult::of($reference + ['subWorkflow' => [
                'id' => $child->id,
                'definitionId' => $child->definitionId,
                'status' => $child->status->value,
                'context' => $child->publicContext(),
            ]]);
        }

        return ActionResult::awaitChild($child->id, $reference);
    }
}
