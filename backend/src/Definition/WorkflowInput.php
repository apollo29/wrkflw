<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Ein Wert, den ein Workflow beim Start erwartet.
 *
 * Bisher stand nirgends, was eine Definition braucht. Wer sie startet, musste
 * die Schritte lesen und die Platzhalter zusammensuchen; fehlte etwas, lief
 * der Ablauf trotzdem an und verschickte eine Mail an eine leere Adresse.
 *
 * Die Deklaration ist freiwillig — eine Definition ohne `inputs` verhaelt sich
 * wie bisher.
 */
final class WorkflowInput
{
    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly bool $required,
        /** Ein Beispielwert fuer den Editor; rein erklaerend. */
        public readonly string $beispiel,
    ) {
    }

    /**
     * Liest einen Eintrag, oder null, wenn er keinen Namen hat.
     *
     * Namenlos passiert im Alltag: der Editor legt eine leere Zeile an, sobald
     * jemand «+ Feld» drueckt. Eine solche Zeile darf keinen Start blockieren.
     *
     * @param array<string,mixed> $d
     */
    public static function fromArray(array $d): ?self
    {
        $name = self::text($d['name'] ?? null);
        if ($name === '') {
            return null;
        }

        $label = self::text($d['label'] ?? null);

        return new self(
            name: $name,
            label: $label !== '' ? $label : $name,
            // Ohne Angabe NICHT Pflicht: der umgekehrte Vorgabewert wuerde
            // bestehende Definitionen beim ersten Speichern im Editor scharf
            // schalten, ohne dass jemand das wollte.
            required: ($d['required'] ?? false) === true,
            beispiel: self::text($d['beispiel'] ?? null),
        );
    }

    /**
     * Ein Textwert aus der Definition, oder '' wenn keiner dasteht.
     *
     * Nur echte Zeichenketten zaehlen. Eine Zahl als Name waere zwar
     * technisch moeglich, aber niemand meint das — sie als «nicht angegeben»
     * zu behandeln ist ehrlicher als sie stillschweigend umzuwandeln.
     */
    private static function text(mixed $wert): string
    {
        return is_string($wert) ? trim($wert) : '';
    }

    /**
     * Fehlt dieser Wert im uebergebenen Kontext?
     *
     * Ein Schluessel, der da ist, aber leer, zaehlt als fehlend — genau der
     * Fall, der eine Mail an eine leere Adresse schickt. `false` und `0` sind
     * dagegen Werte: wer ein Ja/Nein-Feld als Pflicht deklariert, meint «muss
     * gesetzt sein», nicht «muss wahr sein».
     *
     * @param array<string,mixed> $context
     */
    public function fehltIn(array $context): bool
    {
        if (!$this->required) {
            return false;
        }
        if (!array_key_exists($this->name, $context)) {
            return true;
        }

        $wert = $context[$this->name];
        if ($wert === null) {
            return true;
        }

        return is_string($wert) && trim($wert) === '';
    }
}
