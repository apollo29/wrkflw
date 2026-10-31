<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Contracts\AdminTriggerRepositoryInterface;
use WorkflowEngine\Contracts\TriggerSourceInterface;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Trigger\ModelSearchTrigger;
use WorkflowEngine\Trigger\RegisteredTrigger;

/**
 * Die vom Admin eingerichteten Trigger im Hintergrundlauf (F-142): nur aktive, nur wenn ihr
 * Takt erreicht ist, mit Sperrfrist je Datensatz (F-143). Der Abfragezeitpunkt wird vor dem
 * Abfragen gesetzt, damit ein fehlerhafter Trigger nicht jede Minute erneut läuft.
 */
final readonly class AdminTriggerSource implements TriggerSourceInterface
{
    public function __construct(
        private AdminTriggerRepositoryInterface $triggers,
        private DataGateway $data,
        private RestOptions $options = new RestOptions(),
    ) {
    }

    public function triggers(\DateTimeImmutable $now): array
    {
        $due = [];
        foreach ($this->triggers->allTriggers() as $trigger) {
            if (!$trigger->isDue($now)) {
                continue;
            }
            $this->triggers->markPolled($trigger->id, $now);
            $due[] = new RegisteredTrigger(
                new ModelSearchTrigger($this->data, $trigger->model, $trigger->conditions, $trigger->definitionId, $trigger->inputs, $this->options->maxResults),
                $trigger->key(),
                max(1, $trigger->lockDays) * 86_400,
            );
        }

        return $due;
    }
}
