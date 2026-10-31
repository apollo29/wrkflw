<?php

declare(strict_types=1);

namespace WorkflowEngine\Trigger;

use WorkflowEngine\Contracts\TriggerInterface;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\DataGateway;

/**
 * Trigger über die Suche eines Datenmodells (F-61, F-122): je Treffer ein Start, mit
 * zugeordneten Feldern als Startkontext und dem Datensatz als Subjekt. So können auch
 * Daten hinter einer REST-API Abläufe auslösen.
 *
 * Doppelte Starts für denselben Datensatz verhindert die Sperrfrist des Hintergrundlaufs.
 */
final readonly class ModelSearchTrigger implements TriggerInterface
{
    /**
     * @param list<Condition>       $conditions mit UND verknüpft
     * @param array<string, string> $inputs     Name im Startkontext => Feld des Modells
     */
    public function __construct(
        private DataGateway $data,
        private string $model,
        private array $conditions,
        private string $definitionId,
        private array $inputs,
        private int $limit = 100,
    ) {
    }

    public function poll(): array
    {
        $starts = [];
        foreach ($this->data->find($this->model, $this->conditions, $this->limit) as $record) {
            $context = [];
            foreach ($this->inputs as $input => $field) {
                $context[$input] = $record->get($field);
            }
            $starts[] = new TriggerStart($this->definitionId, $context, $this->model, $record->id);
        }

        return $starts;
    }
}
