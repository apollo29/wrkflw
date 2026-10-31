<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ActionResult;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\Record;

/**
 * Schreibt Werte in genau einen Datensatz (F-34 bis F-38). Konfiguration wie in 2.x:
 *
 *     "action": "write_data",
 *     "config": {
 *         "entity": "antrag",
 *         "id": "{{antrag_id}}",
 *         "values": {
 *             "status": "freigegeben",
 *             "geprueft_am": "{{now}}",
 *             "kodex": { "wert": "unterzeichnet", "wenn": "context['gelesen'] == true", "sonst": "" }
 *         },
 *         "anlegen": false,
 *         "as": "written"
 *     }
 *
 * Bei einem bedingten Wert gilt `wert`, wenn `wenn` zutrifft, sonst `sonst`; fehlt `sonst`,
 * bleibt das Feld unverändert. «Auf leer setzen» und «stehen lassen» sind also
 * verschiedene Ergebnisse (F-35). Ein Wert, der nur aus einem Platzhalter besteht, behält
 * den Typ des Kontextwerts.
 *
 * Ergebnis: `<as>` (ob geschrieben wurde) und `<as>Count` (wie viele Felder sich
 * geändert haben). «Nichts geschrieben» ist ein Weg im Ablauf, kein Fehler (F-38).
 * Löschen, Massenänderungen und Feldnamen aus dem Kontext gibt es nicht (F-36).
 */
final readonly class WriteDataAction implements ActionInterface, ConfigurableActionInterface
{
    private const string COLUMN_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        private DataGateway $data,
        private PlaceholderRenderer $renderer,
        private ExpressionEvaluatorInterface $expressions,
    ) {
    }

    public function label(): string
    {
        return 'Daten schreiben';
    }

    public function configSchema(): array
    {
        return [
            ['name' => 'entity', 'label' => 'Tabelle', 'type' => 'write-entity-ref'],
            ['name' => 'id', 'label' => 'Datensatz-ID (z. B. {{diplomId}})', 'type' => 'text'],
            ['name' => 'values', 'label' => 'Zu schreibende Felder', 'type' => 'field-value-map'],
            ['name' => 'anlegen', 'label' => 'Datensatz anlegen, wenn es ihn noch nicht gibt', 'type' => 'boolean'],
            ['name' => 'as', 'label' => 'Ergebnis-Variable', 'type' => 'text'],
        ];
    }

    public function execute(WorkflowInstance $instance, Step $step): ActionResult
    {
        $config = $step->config;
        $context = $instance->publicContext();
        $as = self::text($config, 'as');
        $as = $as === '' ? 'written' : $as;
        $nothing = ActionResult::of([$as => false, $as . 'Count' => 0]);

        $entity = self::text($config, 'entity');
        $id = trim($this->renderer->render(self::text($config, 'id'), $context));
        $values = $this->values($config['values'] ?? null, $context);
        if ($entity === '' || $id === '' || $values === []) {
            return $nothing;
        }

        $record = $this->data->load($entity, $id);
        if ($record === null) {
            if (!self::flag($config['anlegen'] ?? false)) {
                return $nothing;
            }
            $record = Record::create($entity, $id);
        }
        foreach ($values as $field => $value) {
            $record = $record->with($field, $value);
        }

        $result = $this->data->save(
            $record,
            new ChangeOrigin($instance->definitionId, $instance->definitionVersion, $instance->id, $step->name),
        );

        return ActionResult::of([$as => $result->written, $as . 'Count' => count($result->changedFields)]);
    }

    /**
     * Die zu schreibenden Felder samt Werten. Was keine einfache Spalte sein kann, wird
     * verworfen; ein bedingter Wert ohne zutreffenden Zweig fehlt ganz.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function values(mixed $raw, array $context): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $field => $value) {
            if (!is_string($field) || preg_match(self::COLUMN_PATTERN, $field) !== 1) {
                continue;
            }
            if (is_array($value)) {
                $condition = isset($value['wenn']) && is_string($value['wenn']) ? trim($value['wenn']) : '';
                $applies = $condition === '' || $this->expressions->evaluate($condition, ['context' => $context]);
                $branch = $applies ? 'wert' : 'sonst';
                if (!array_key_exists($branch, $value)) {
                    continue;
                }
                $value = $value[$branch];
            }
            if (is_string($value)) {
                $placeholder = PlaceholderRenderer::singlePlaceholder($value);
                $out[$field] = $placeholder !== null
                    ? $this->renderer->lookup($placeholder, $context)
                    : $this->renderer->render($value, $context);
            } elseif (is_scalar($value) || $value === null) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /** Ja/Nein aus der Konfiguration; `"false"`, `"0"` und `"nein"` sind Nein. */
    private static function flag(mixed $value): bool
    {
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'nein', 'no'], true);
        }

        return (bool) $value;
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
