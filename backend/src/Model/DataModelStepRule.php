<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

use WorkflowEngine\Action\CheckDataAction;
use WorkflowEngine\Contracts\StepRuleInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\ValidationCode;
use WorkflowEngine\Definition\ValidationError;
use WorkflowEngine\Definition\WorkflowDefinition;

/**
 * Prüft beim Speichern einer Definition, dass die Datenschritte existierende Modelle und
 * Felder nennen und die nötigen Freigaben haben (F-118). So fällt ein Tippfehler im Editor
 * auf und nicht erst im Durchlauf.
 */
final readonly class DataModelStepRule implements StepRuleInterface
{
    public function __construct(private DataModelRegistry $models)
    {
    }

    public function check(Step $step, WorkflowDefinition $definition): array
    {
        if ($step->action !== 'check_data' && $step->action !== 'write_data') {
            return [];
        }

        $messages = [];
        $entity = $step->config['entity'] ?? null;
        if (!is_string($entity) || trim($entity) === '') {
            $messages[] = 'es ist keine Tabelle gewählt.';
        } elseif (!$this->models->has($entity)) {
            $messages[] = DataModelException::unknownModel($entity)->getMessage();
        } else {
            $model = $this->models->get($entity);
            $messages = $step->action === 'check_data' ? $this->checkRead($model, $step) : $this->checkWrite($model, $step);
        }

        return array_map(
            static fn (string $m): ValidationError => new ValidationError(ValidationCode::RuleViolation, "Schritt '{$step->name}': {$m}", $step->name),
            $messages,
        );
    }

    /**
     * @return list<string>
     */
    private function checkRead(DataModel $model, Step $step): array
    {
        $messages = [];
        $field = $step->config['field'] ?? null;
        if (is_string($field) && $field !== '' && !$model->hasField($field)) {
            $messages[] = DataModelException::unknownField($model->name, $field)->getMessage();
        }

        try {
            $extra = CheckDataAction::fieldList($step->config['fields'] ?? null);
        } catch (DataModelException $e) {
            $messages[] = $e->getMessage();
            $extra = [];
        }
        foreach ($extra as $name) {
            if (!$model->hasField($name)) {
                $messages[] = DataModelException::unknownField($model->name, $name)->getMessage();
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function checkWrite(DataModel $model, Step $step): array
    {
        $messages = [];
        if (!$model->canUpdate) {
            $messages[] = DataModelException::operationNotAllowed($model->name, 'Ändern')->getMessage();
        }
        $create = $step->config['anlegen'] ?? false;
        if (($create === true || $create === 'true' || $create === 1) && !$model->canCreate) {
            $messages[] = DataModelException::operationNotAllowed($model->name, 'Anlegen')->getMessage();
        }

        $values = $step->config['values'] ?? null;
        if (!is_array($values) || $values === []) {
            $messages[] = 'es sind keine Felder zum Schreiben gewählt.';

            return $messages;
        }
        foreach (array_keys($values) as $name) {
            $name = (string) $name;
            if (!$model->hasField($name)) {
                $messages[] = DataModelException::unknownField($model->name, $name)->getMessage();
            } elseif (!$model->field($name)->writable) {
                $messages[] = DataModelException::notWritable($model->name, $name)->getMessage();
            }
        }

        return $messages;
    }
}
