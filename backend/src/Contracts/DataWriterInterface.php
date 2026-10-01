<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Die Host-App implementiert dieses Interface, damit ein automatischer
 * Schritt Werte in ihre Datenstruktur schreiben kann — das Gegenstueck zum
 * {@see DataProviderInterface}.
 *
 * WARUM EIN EIGENER PORT UND NICHT EINE METHODE AM DataProvider:
 * Lesen und Schreiben sind nicht dieselbe Erlaubnis. Wer den Lese-Port
 * implementiert, hat damit noch nicht entschieden, dass eine im Editor
 * bearbeitbare Definition seine Tabellen auch aendern darf. Als getrennter
 * Port ist Schreiben etwas, das eine Host-App ausdruecklich dazunimmt — und
 * eine, die ihn nicht bindet, hat die Aktion gar nicht.
 *
 * Die Engine baut kein SQL und kennt keine Spalten. Sie fragt; was tatsaechlich
 * geschrieben werden darf, entscheidet allein der Host (Whitelist je Entitaet
 * UND je Spalte — die Werte kommen aus einer Definition, die ein Admin
 * bearbeitet).
 */
interface DataWriterInterface
{
    /**
     * Werte eines Datensatzes aendern — und, wenn $anlegen gesetzt ist und es
     * ihn nicht gibt, ihn mit dieser ID anlegen.
     *
     * Rueckgabe `false` heisst: der Host hat NICHT geschrieben — unbekannte
     * Entitaet, kein Datensatz, keine erlaubte Spalte uebrig. Das ist kein
     * Fehler, sondern eine Antwort: der Ablauf kann darauf verzweigen. Fuer
     * echte Fehler (Datenbank nicht erreichbar, Wert verletzt eine
     * Bedingung) wirft der Host — dann faellt die Instanz auf `failed`, und
     * der Grund steht in `wf_instance.last_error`, statt lautlos zu
     * verschwinden.
     *
     * @param array<string,scalar|null> $values Spalte => Wert, Platzhalter schon ersetzt
     * @param string $herkunft Woher die Aenderung kommt, fuer das Audit der
     *                         Host-App (z. B. `workflow:kjs-mahnung#<instanz>`).
     *                         Nie leer, nie von aussen gesetzt.
     * @param bool $anlegen Fehlt der Datensatz: anlegen statt `false` melden.
     *                      Der Host darf trotzdem ablehnen — nicht jede
     *                      Tabelle laesst sich aus ID und ein paar Werten
     *                      sinnvoll fuellen, und eine Zeile, der die Haelfte
     *                      fehlt, ist schlimmer als keine.
     */
    public function write(
        string $entity,
        string|int $id,
        array $values,
        string $herkunft,
        bool $anlegen = false,
    ): bool;
}
