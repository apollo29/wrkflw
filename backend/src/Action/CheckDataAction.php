<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\DataProviderInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Eingebaute Aktion, die einen Wert aus einer Host-Tabelle liest und in den Kontext
 * schreibt — der eigentliche Vergleich passiert danach ganz normal ueber die
 * Uebergangs-Bedingungen (Assistent). Zugriff nur ueber den DataProviderInterface-Port.
 *
 * Step-Konfiguration:
 *   "action": "check_data",
 *   "config": {
 *       "entity": "order",        // Tabelle/Entitaet (aus dem Daten-Katalog)
 *       "id":     "{{orderId}}",  // ID des Datensatzes (mit {{platzhalter}})
 *       "field":  "status",       // zu lesende Spalte (der Pruefwert)
 *       "fields": ["vorname", "mail"], // weitere Spalten, zum Anzeigen
 *       "as":     "orderStatus"   // Kontext-Key fuer den Wert (Default: checkedValue)
 *   }
 *
 * Ergebnis im Kontext: <as> = Feldwert (oder null), <as>Found = ob der Datensatz
 * existierte, und je Eintrag aus `fields` ein <as>_<spalte>.
 *
 * Die ganze Zeile (`fields: "*"`) gibt es bewusst NICHT: der Kontext ist auf der
 * oeffentlichen Seite sichtbar, und was gelesen wird, soll in der Definition
 * dastehen statt davon abzuhaengen, welche Spalten die Tabelle gerade hat.
 */
final class CheckDataAction implements ActionInterface, ConfigurableActionInterface
{
    public function __construct(private readonly DataProviderInterface $data)
    {
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

    public function execute(WorkflowInstance $instance, Step $step): array
    {
        $config = $step->config;
        $context = $instance->context;

        $entity = $this->stringConfig($config, 'entity');
        $id = $this->interpolate($this->stringConfig($config, 'id'), $context);
        $field = $this->stringConfig($config, 'field');
        $as = $this->stringConfig($config, 'as');
        if ($as === '') {
            $as = 'checkedValue';
        }

        $row = ($entity !== '' && $id !== '') ? $this->data->get($entity, $id) : null;
        $value = is_array($row) ? ($row[$field] ?? null) : null;

        $ergebnis = [
            $as => $value,
            $as . 'Found' => $row !== null,
        ];

        // Auch ohne Datensatz entsteht je Spalte ein Schluessel — sonst bliebe
        // ein Anzeigefeld beim naechsten Durchlauf auf dem alten Wert stehen.
        foreach ($this->columns($config) as $spalte) {
            $ergebnis[$as . '_' . $spalte] = is_array($row) ? ($row[$spalte] ?? null) : null;
        }

        return $ergebnis;
    }

    /**
     * Die Spalten aus `fields` — nur brauchbare Namen, ohne Dubletten.
     *
     * `*` ist kein Platzhalter, sondern ein gewoehnlicher Name, den keine
     * Tabelle traegt: er faellt hier heraus, damit aus einem Tippfehler kein
     * versehentliches «alles lesen» wird.
     *
     * @param array<string,mixed> $config
     *
     * @return list<string>
     */
    private function columns(array $config): array
    {
        $roh = $config['fields'] ?? null;
        if (!is_array($roh)) {
            return [];
        }

        $spalten = [];
        foreach ($roh as $eintrag) {
            if (!is_string($eintrag)) {
                continue;
            }
            $name = trim($eintrag);
            if ($name === '' || $name === '*' || in_array($name, $spalten, true)) {
                continue;
            }
            $spalten[] = $name;
        }

        return $spalten;
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
     * Ersetzt {{key}}-Platzhalter durch Kontextwerte.
     *
     * @param array<string,mixed> $context
     */
    private function interpolate(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            static function (array $m) use ($context): string {
                $value = $context[$m[1]] ?? '';

                return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
            },
            $template,
        ) ?? $template;
    }
}
