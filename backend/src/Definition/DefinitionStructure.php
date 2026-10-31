<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Die Form eines Ablaufs ohne Inhalte, für das Vorschaubild der Übersicht (E-63, 9.13):
 * Schritte mit ihrer Art, wie der Editor sie zeigt, und die Ziele ihrer Übergänge.
 *
 * @phpstan-type StructureStep array{name: string, kind: string, transitions: list<array{to: string, conditional: bool, event: ?string}>}
 * @phpstan-type Structure array{startStep: string, steps: list<StructureStep>}
 */
final class DefinitionStructure
{
    /** Aktionen mit eigener Karte im Editor (F-80). */
    private const array ACTION_KINDS = [
        'start_workflow' => 'workflow',
        'check_data' => 'check',
        'write_data' => 'write',
    ];

    private function __construct()
    {
    }

    /**
     * @return Structure
     */
    public static function of(WorkflowDefinition $definition): array
    {
        $steps = [];
        foreach ($definition->steps as $step) {
            $steps[] = [
                'name' => $step->name,
                'kind' => self::kind($step),
                'transitions' => array_map(
                    static fn (Transition $t): array => ['to' => $t->to, 'conditional' => $t->isConditional(), 'event' => $t->event],
                    $step->transitions,
                ),
            ];
        }

        return ['startStep' => $definition->startStep, 'steps' => $steps];
    }

    /** automatic, interactive, timer, workflow, check oder write. */
    public static function kind(Step $step): string
    {
        if ($step->isAutomatic() && $step->action !== null && isset(self::ACTION_KINDS[$step->action])) {
            return self::ACTION_KINDS[$step->action];
        }

        return $step->type->value;
    }
}
