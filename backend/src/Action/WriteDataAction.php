<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\DataWriterInterface;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
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
 *           "gueltig_bis": "{{neu_gueltig_bis}}",
 *           "erworben_am": "{{now}}",      // eingebaute Uhr, siehe eingebaut()
 *           // Bedingt: nur schreiben, wenn der Ausdruck zutrifft.
 *           "kodex_status": {
 *               "wert":  "unterzeichnet",
 *               "wenn":  "context['kodex_gelesen'] == true",
 *               "sonst": ""                // weglassen = Spalte unberuehrt
 *           }
 *       },
 *       "anlegen": true,                  // fehlt der Datensatz: anlegen
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
 *  - LOESCHEN. Sie aendert einen Datensatz; weg nimmt sie keinen.
 *  - Eine Spalte aus dem Kontext bestimmen (`"spalte": "{{welche}}"` als
 *    SCHLUESSEL). Welche Spalten ein Schritt anfasst, steht in der Definition
 *    und ist dort nachlesbar; stammte der Name aus dem Kontext, waere er von
 *    einer Eingabe abhaengig und damit von aussen steuerbar.
 *  - Mehrere Datensaetze auf einmal (kein `where`). Ein Tippfehler darf
 *    hoechstens eine Zeile treffen.
 */
final class WriteDataAction implements ActionInterface, ConfigurableActionInterface
{
    public function __construct(
        private readonly DataWriterInterface $writer,
        /**
         * Fuer `wenn` je Feld — dieselbe Sprache und derselbe Geltungsbereich
         * wie bei den Uebergangs-Bedingungen (`context[...]`, `now`).
         *
         * Optional, weil eine Host-App ohne bedingte Werte ihn nicht braucht.
         * Fehlt er und eine Definition benutzt `wenn`, wirft die Aktion. Eine
         * ignorierte Bedingung waere schlimmer: sie schriebe, wo gerade nicht
         * geschrieben werden sollte.
         */
        private readonly ?ExpressionEvaluatorInterface $expr = null,
    ) {
    }

    public function configSchema(): array
    {
        return [
            ['name' => 'entity', 'label' => 'Tabelle', 'type' => 'write-entity-ref'],
            ['name' => 'id', 'label' => 'Datensatz-ID (z. B. {{diplomId}})', 'type' => 'text'],
            ['name' => 'values', 'label' => 'Zu schreibende Felder', 'type' => 'field-value-map'],
            [
                'name' => 'anlegen',
                'label' => 'Datensatz anlegen, wenn es ihn noch nicht gibt',
                'type' => 'boolean',
            ],
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

        $ok = $this->writer->write(
            $entity,
            $id,
            $values,
            $this->herkunft($instance),
            $this->boolConfig($config, 'anlegen'),
        );

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
            // Bedingte Form: {"wert": ..., "wenn": ..., "sonst": ...}
            if (is_array($wert)) {
                $entschieden = $this->bedingt($wert, $context);
                if ($entschieden !== self::NICHT_SCHREIBEN) {
                    $out[$spalte] = $entschieden;
                }
                continue;
            }
            if (is_string($wert)) {
                $out[$spalte] = $this->interpolate($wert, $context);
                continue;
            }
            if (is_bool($wert) || is_int($wert) || is_float($wert) || $wert === null) {
                $out[$spalte] = $wert;
            }
            // Alles andere (Objekte) hat in einer Spalte nichts zu suchen und
            // wird weggelassen — nicht als leerer String geschrieben, denn das
            // waere eine Aenderung, die niemand wollte.
        }

        return $out;
    }

    /**
     * Ein Wert, der NICHT geschrieben werden soll. Ein eigener Sentinel und
     * nicht `null`: `null` ist ein gueltiger Spaltenwert, «gar nicht anfassen»
     * ist etwas anderes als «auf leer setzen».
     */
    private const NICHT_SCHREIBEN = "\0nicht-schreiben\0";

    /**
     * Die bedingte Form eines Wertes.
     *
     *   {"wert": "unterzeichnet", "wenn": "context['x'] == true", "sonst": ""}
     *
     * Trifft `wenn` zu, gilt `wert`. Trifft es nicht zu, gilt `sonst` — und
     * fehlt `sonst`, wird die Spalte gar nicht angefasst. Das ist der
     * Unterschied zwischen «setze auf leer» und «lass stehen», und beides
     * kommt vor: ein Status, der zurueckgesetzt werden soll, und ein Datum,
     * das bleiben soll, wie es ist.
     *
     * Ohne `wenn` ist die Form nur eine umstaendliche Schreibweise fuer `wert`
     * — erlaubt, damit der Editor sie nicht aufloesen muss, wenn jemand eine
     * Bedingung wieder entfernt.
     *
     * @param array<mixed>        $eintrag
     * @param array<string,mixed> $context
     *
     * @return scalar|null Oder self::NICHT_SCHREIBEN
     */
    private function bedingt(array $eintrag, array $context): string|int|float|bool|null
    {
        $wenn = isset($eintrag['wenn']) && is_string($eintrag['wenn']) ? trim($eintrag['wenn']) : '';
        $trifftZu = true;

        if ($wenn !== '') {
            if ($this->expr === null) {
                throw new \RuntimeException(
                    'write_data: `wenn` verlangt einen ExpressionEvaluator, der Host bindet keinen.'
                );
            }
            // Derselbe Geltungsbereich wie bei den Uebergangs-Bedingungen,
            // damit ein Ausdruck an beiden Stellen dasselbe bedeutet.
            $trifftZu = $this->expr->evaluate($wenn, ['context' => $context, 'now' => time()]);
        }

        $schluessel = $trifftZu ? 'wert' : 'sonst';
        if (!array_key_exists($schluessel, $eintrag)) {
            return self::NICHT_SCHREIBEN;
        }

        $wert = $eintrag[$schluessel];
        if (is_string($wert)) {
            return $this->interpolate($wert, $context);
        }

        return is_bool($wert) || is_int($wert) || is_float($wert) || $wert === null
            ? $wert
            : self::NICHT_SCHREIBEN;
    }

    /**
     * Ein Ja/Nein-Schalter aus der Konfiguration.
     *
     * Der Editor schreibt `true`/`false`; eine von Hand bearbeitete Definition
     * kann `"true"` oder `"1"` enthalten. `"false"` und `"0"` sind NEIN —
     * PHPs `(bool)` macht aus `"false"` sonst ein Ja.
     *
     * @param array<string,mixed> $config
     */
    private function boolConfig(array $config, string $key): bool
    {
        $wert = $config[$key] ?? false;
        if (is_string($wert)) {
            return !in_array(strtolower(trim($wert)), ['', '0', 'false', 'nein', 'no'], true);
        }

        return (bool) $wert;
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
     *   {{now.date}}     2026-10-01    dasselbe, ausgeschrieben
     *   {{now.ymd}}      2026-10-01    dasselbe, nach dem Format benannt —
     *                                  wer das Format meint, sucht danach
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
            'now', 'now.date', 'now.ymd' => date('Y-m-d'),
            'now.datetime' => date('c'),
            'now.year' => date('Y'),
            default => null,
        };
    }
}
