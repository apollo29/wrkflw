<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ActionResult;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModelException;

/**
 * Liest Werte eines Datensatzes in den Kontext (F-32, F-33). Konfiguration wie in 2.x:
 *
 *     "action": "check_data",
 *     "config": { "entity": "antrag", "id": "{{antrag_id}}", "field": "status",
 *                 "fields": ["betrag", "mail"], "as": "antrag" }
 *
 * Ergebnis: `antrag` (Wert von `field`), `antragFound`, und je weiteres Feld
 * `antrag_<feld>`. Jeder Schlüssel entsteht auch ohne Datensatz, damit kein alter Wert
 * stehen bleibt. Was gelesen wird, steht ausdrücklich in der Definition; «alle Felder»
 * gibt es nicht, weil der Kontext auf öffentlichen Seiten sichtbar sein kann.
 */
final readonly class CheckDataAction implements ActionInterface, ConfigurableActionInterface
{
    public function __construct(
        private DataGateway $data,
        private PlaceholderRenderer $renderer = new PlaceholderRenderer(),
    ) {
    }

    public function label(): string
    {
        return 'Datencheck';
    }

    public function configSchema(): array
    {
        return [
            ['name' => 'entity', 'label' => 'Tabelle', 'type' => 'entity-ref'],
            ['name' => 'id', 'label' => 'Datensatz-ID (z. B. {{orderId}})', 'type' => 'text'],
            ['name' => 'field', 'label' => 'Feld', 'type' => 'field-ref'],
            ['name' => 'fields', 'label' => 'Weitere Felder (nur anzeigen)', 'type' => 'field-ref-list'],
            ['name' => 'as', 'label' => 'Ergebnis-Variable', 'type' => 'text'],
        ];
    }

    public function execute(WorkflowInstance $instance, Step $step): ActionResult
    {
        $config = $step->config;
        $entity = self::text($config, 'entity');
        $field = self::text($config, 'field');
        $as = self::text($config, 'as');
        $as = $as === '' ? 'checkedValue' : $as;
        $id = trim($this->renderer->render(self::text($config, 'id'), $instance->publicContext()));
        $extra = self::fieldList($config['fields'] ?? null);

        $record = null;
        if ($entity !== '') {
            $model = $this->data->model($entity);
            foreach ([$field, ...$extra] as $name) {
                if ($name !== '') {
                    $model->field($name);
                }
            }
            if ($id !== '') {
                $record = $this->data->load($entity, $id);
            }
        }

        $result = [
            $as => $record !== null && $field !== '' ? $record->get($field) : null,
            $as . 'Found' => $record !== null,
        ];
        foreach ($extra as $name) {
            $result[$as . '_' . $name] = $record?->get($name);
        }

        return ActionResult::of($result);
    }

    /**
     * @return list<string>
     */
    public static function fieldList(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $names = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                continue;
            }
            $name = trim($entry);
            if ($name === '*') {
                throw new DataModelException('«Alle Felder» gibt es nicht; bitte die Felder einzeln wählen.');
            }
            if (!in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function text(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? trim($value) : '';
    }
}
