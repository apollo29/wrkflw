<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\WorkflowStarterInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Eingebaute Aktion zum Verknuepfen von Workflows: startet aus einem Schritt heraus
 * einen weiteren Workflow.
 *
 * Step-Konfiguration:
 *   "action": "start_workflow",
 *   "config": {
 *       "workflowId":        "onboarding",   // Ziel-Definition
 *       "waitForCompletion": true|false      // auf Abschluss des Kindes warten?
 *   }
 *
 * Der Kind-Workflow erbt den (um interne "__"-Schluessel bereinigten) Eltern-Kontext.
 *
 * - waitForCompletion=false (feuer-und-vergiss): das Kind startet, der Eltern-Schritt
 *   laeuft sofort weiter. Die Kind-Instanz-ID liegt danach unter 'startedWorkflow'.
 * - waitForCompletion=true (Sub-Workflow): laeuft das Kind synchron durch, geht es
 *   direkt weiter. Haelt das Kind an (interaktiv/Timer), setzt die Aktion den Marker
 *   {@see WorkflowStarterInterface::AWAIT_WORKFLOW}; die Engine haelt den Eltern dann
 *   an und weckt ihn, sobald das Kind fertig ist. Das Ergebnis liegt unter 'subWorkflow'.
 */
final class SubWorkflowAction implements ActionInterface, ConfigurableActionInterface
{
    /** Obergrenze der Verschachtelungstiefe (Guard gegen Endlos-Ketten). */
    private const MAX_DEPTH = 20;

    /**
     * @param \Closure(): WorkflowStarterInterface $starter Lazy-Resolver der Engine
     *        (bricht den Zirkelbezug Action -> Engine -> Registry -> Action).
     */
    public function __construct(private readonly \Closure $starter)
    {
    }

    public function configSchema(): array
    {
        return [
            ['name' => 'workflowId', 'label' => 'Workflow', 'type' => 'workflow-ref'],
            ['name' => 'waitForCompletion', 'label' => 'Auf Abschluss warten', 'type' => 'boolean'],
        ];
    }

    public function execute(WorkflowInstance $instance, Step $step): array
    {
        $definitionId = $this->stringConfig($step->config, 'workflowId');
        if ($definitionId === '') {
            throw new WorkflowException("start_workflow in Step '{$step->name}': 'workflowId' fehlt.");
        }

        $depth = $this->depth($instance);
        if ($depth >= self::MAX_DEPTH) {
            throw new WorkflowException(
                "start_workflow in Step '{$step->name}': maximale Verschachtelungstiefe erreicht."
            );
        }

        $wait = $this->boolConfig($step->config, 'waitForCompletion');

        $childContext = $this->publicContext($instance->context);
        $childContext[WorkflowStarterInterface::SUB_DEPTH] = $depth + 1;
        if ($wait) {
            $childContext[WorkflowStarterInterface::PARENT_LINK] = ['instanceId' => $instance->id];
        }

        $starter = ($this->starter)();
        $child = $starter->startWorkflow(
            $definitionId,
            $childContext,
            $instance->subjectType,
            $instance->subjectId,
        );

        $reference = ['id' => $child->id, 'definitionId' => $definitionId];

        if (!$wait) {
            return ['startedWorkflow' => $reference];
        }

        if ($child->isFinished()) {
            // Kind lief synchron durch -> kein Warten noetig, Ergebnis direkt liefern.
            return [
                'startedWorkflow' => $reference,
                'subWorkflow' => $this->summary($child),
            ];
        }

        // Kind haelt an -> Engine haelt den Eltern an diesem Marker an.
        return [
            'startedWorkflow' => $reference,
            WorkflowStarterInterface::AWAIT_WORKFLOW => $child->id,
        ];
    }

    /**
     * @return array{id:string,definitionId:string,status:string,context:array<string,mixed>}
     */
    private function summary(WorkflowInstance $child): array
    {
        return [
            'id' => $child->id,
            'definitionId' => $child->definitionId,
            'status' => $child->status,
            'context' => $this->publicContext($child->context),
        ];
    }

    private function depth(WorkflowInstance $instance): int
    {
        $depth = $instance->context[WorkflowStarterInterface::SUB_DEPTH] ?? 0;

        return is_int($depth) ? $depth : 0;
    }

    /**
     * Entfernt engine-interne Schluessel (Prefix "__"), sodass ein Kind-Workflow nur
     * den fachlichen Kontext erbt.
     *
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private function publicContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (!str_starts_with($key, '__')) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function stringConfig(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string,mixed> $config
     */
    private function boolConfig(array $config, string $key): bool
    {
        return filter_var($config[$key] ?? false, FILTER_VALIDATE_BOOL);
    }
}
