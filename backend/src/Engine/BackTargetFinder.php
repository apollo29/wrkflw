<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Das Ziel von «Ein Schritt zurück»: der zuletzt VERLASSENE interaktive Schritt (F-56).
 *
 * Automatische Schritte dazwischen werden übersprungen, damit ihre Aktion nicht ein
 * zweites Mal läuft. Gemeint ist «die vorherige Maske», nicht «führe das nochmal aus».
 */
final readonly class BackTargetFinder
{
    public function __construct(private WorkflowRepositoryInterface $repo)
    {
    }

    public function find(WorkflowInstance $instance, WorkflowDefinition $definition): ?string
    {
        foreach (array_reverse($this->repo->findHistory($instance->id)) as $entry) {
            if ($entry->kind !== HistoryKind::Transition) {
                continue;
            }
            $name = $entry->step;
            if ($name === null || $name === $instance->currentStep || !$definition->hasStep($name)) {
                continue;
            }
            if ($definition->step($name)->isInteractive()) {
                return $name;
            }
        }

        return null;
    }
}
