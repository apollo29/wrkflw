<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\DataWriterInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Eingebaute Aktion, die Werte in eine Host-Tabelle schreibt — das Gegenstueck
 * zum {@see CheckDataAction}. Zugriff nur ueber den DataWriterInterface-Port;
 * was erlaubt ist, entscheidet der Host.
 *
 * Step-Konfiguration:
 *   "action": "write_data",
 *   "config": {
 *       "entity": "js_diplom",            // Tabelle/Entitaet (aus dem Schreib-Katalog)
 *       "id":     "{{diplomId}}",         // Datensatz (mit {{platzhalter}})
 *       "values": {                        // Spalte => Wert, Wert mit {{platzhalter}}
 *           "status":      "anerkannt",
 *           "gueltig_bis": "{{neu_gueltig_bis}}"
 *       },
 *       "as":     "gespeichert"            // Kontext-Key (Default: written)
 *   }
 *
 * Ergebnis im Kontext: <as> = ob geschrieben wurde, <as>Count = wie viele
 * Spalten gesendet wurden. Damit laesst sich ganz normal ueber die
 * Uebergangs-Bedingungen verzweigen — `written == false` ist ein Weg, kein
 * Absturz.
 *
 * WAS DIE AKTION BEWUSST NICHT KANN:
 *
 *  - Datensaetze ANLEGEN oder LOESCHEN. Sie aendert einen, der schon da ist.
 *    Ein Ablauf, der im Editor entsteht, soll Bestand pflegen und keine
 *    Zeilen erzeugen, deren Herkunft niemand mehr zuordnen kann.
 *  - Eine Spalte aus dem Kontext bestimmen (`"spalte": "{{welche}}"` als
 *    SCHLUESSEL). Welche Spalten ein Schritt anfasst, steht in der Definition
 *    und ist dort nachlesbar; stammte der Name aus dem Kontext, waere er von
 *    einer Eingabe abhaengig und damit von aussen steuerbar.
 *  - Mehrere Datensaetze auf einmal (kein `where`). Ein Tippfehler darf
 *    hoechstens eine Zeile treffen.
 */
final class WriteDataAction implements ActionInterface, ConfigurableActionInterface
{
    public function __construct(private readonly DataWriterInterface $writer)
    {
    }

    public function configSchema(): array
    {
        return [
            ['name' => 'entity', 'label' => 'Tabelle', 'type' => 'write-entity-ref'],
            ['name' => 'id', 'label' => 'Datensatz-ID (z. B. {{diplomId}})', 'type' => 'text'],
            ['name' => 'values', 'label' => 'Zu schreibende Felder', 'type' => 'field-value-map'],
            ['name' => 'as', 'label' => 'Ergebnis-Variable', 'type' => 'text'],
        ];
    }

    public function execute(WorkflowInstance $instance, Step $step): array
    {
        $config = $step->config;
        $context = $instance->context;

        $entity = $this->stringConfig($config, 'entity');
        $id = $this->interpolate($this->stringConfig($config, 'id'), $context);
        $as = $this->stringConfig($config, 'as');
        if ($as === '') {
            $as = 'written';
        }

        $values = $this->values($config, $context);

        // Nichts Vollstaendiges zu tun — und dann auch nichts tun. Eine halb
        // ausgefuellte Konfiguration (Tabelle ohne Datensatz, Felder ohne
        // Tabelle) darf nicht in einen Schreibversuch laufen, der die
        // fehlende Angabe als leeren String mitnimmt.
        if ($entity === '' || $id === '' || $values === []) {
            return [$as => false, $as . 'Count' => 0];
        }

        $ok = $this->writer->write($entity, $id, $values, $this->herkunft($instance));

        return [$as => $ok, $as . 'Count' => count($values)];
    }

    /**
     * Woher die Aenderung kommt — fuer das Audit der Host-App. Definition und
     * Instanz, damit eine Zeile im Nachhinein einem Ablauf zuzuordnen ist und
     * nicht bloss «einem Workflow».
     */
    private function herkunft(WorkflowInstance $instance): string
    {
        return 'workflow:' . $instance->definitionId . '#' . $instance->id;
    }

    /**
     * Die zu schreibenden Spalten samt Werten, Platzhalter ersetzt.
     *
     * Verworfen wird alles, was keine Spalte sein kann: der Schluessel muss
     * ein einfacher Name sein (`[A-Za-z_][A-Za-z0-9_]*`). Das ist keine
     * SQL-Absicherung — die macht der Host —, sondern haelt Unsinn aus einer
     * von Hand bearbeiteten Definition fern, bevor er als Spaltenname beim
     * Host ankommt.
     *
     * Werte werden zu Strings interpoliert; `true`/`false`/`null` und Zahlen
     * bleiben, was sie sind, damit ein Flag nicht als Text `"1"` in einer
     * Zahlenspalte landet.
     *
     * @param array<string,mixed> $config
     * @param array<string,mixed> $context
     *
     * @return array<string,scalar|null>
     */
    private function values(array $config, array $context): array
    {
        $roh = $config['values'] ?? null;
        if (!is_array($roh)) {
            return [];
        }

        $out = [];
        foreach ($roh as $spalte => $wert) {
            if (!is_string($spalte) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $spalte) !== 1) {
                continue;
            }
            if (is_string($wert)) {
                $out[$spalte] = $this->interpolate($wert, $context);
                continue;
            }
            if (is_bool($wert) || is_int($wert) || is_float($wert) || $wert === null) {
                $out[$spalte] = $wert;
            }
            // Alles andere (Arrays, Objekte) hat in einer Spalte nichts zu
            // suchen und wird weggelassen — nicht als leerer String
            // geschrieben, denn das waere eine Aenderung, die niemand wollte.
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
     * Ersetzt {{key}}-Platzhalter durch Kontextwerte — und durch die
     * eingebauten, die kein Kontext liefern kann (siehe eingebaut()).
     *
     * @param array<string,mixed> $context
     */
    private function interpolate(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            function (array $m) use ($context): string {
                $eingebaut = $this->eingebaut($m[1]);
                if ($eingebaut !== null) {
                    return $eingebaut;
                }

                $value = $context[$m[1]] ?? '';

                return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
            },
            $template,
        ) ?? $template;
    }

    /**
     * Eingebaute Platzhalter — heute nur die Uhr.
     *
     * WARUM ES SIE BRAUCHT: «setze das Datum auf heute» ist der Normalfall
     * eines Schreib-Schritts, und ohne eingebaute Uhr müsste der Wert von
     * aussen in den Kontext kommen. Bei einem Ablauf, der vor zwei Wochen
     * gestartet ist und jetzt durch einen Timer weiterläuft, wäre das der
     * Zeitpunkt des STARTS — also nicht das, was jemand meint, der «heute»
     * schreibt.
     *
     * `now` ist in den Übergangs-Bedingungen bereits ein eingebauter Name
     * (ExpressionLanguage). Dass er hier dasselbe bedeutet, ist Absicht; ein
     * Kontext-Schlüssel gleichen Namens wird deshalb verdeckt.
     *
     *   {{now}}          2026-10-01    Datum, ISO — so stehen Datumswerte in
     *                                  den Tabellen, und so vergleichen die
     *                                  Trigger sie
     *   {{now.datetime}} 2026-10-01T14:32:05+02:00
     *   {{now.year}}     2026
     *
     * Ein unbekanntes `now.irgendwas` ist KEIN eingebauter Platzhalter und
     * fällt damit auf die Kontext-Suche zurück — wie jeder andere Schlüssel,
     * den es nicht gibt, wird es leer. Die drei oben sind die Liste.
     */
    private function eingebaut(string $key): ?string
    {
        return match ($key) {
            'now', 'now.date' => date('Y-m-d'),
            'now.datetime' => date('c'),
            'now.year' => date('Y'),
            default => null,
        };
    }
}
