<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Prueft einen Ausdruck, ohne ihn auszuwerten — fuer den Moment des
 * SPEICHERNS statt des Ausfuehrens.
 *
 * DER FEHLER, DEN DAS FAENGT: eine im Editor gebaute Bedingung
 * `"when": "daten_korrekt == true"`, gemeint war `context['daten_korrekt']`.
 * Die Sprache kennt nur die Wurzeln `context` und `now`; ein blosser Name ist
 * keine Variable, sondern ein Fehler — und einer, der erst beim Klick auf
 * «Absenden» auffliegt, als Serverfehler auf der oeffentlichen Seite.
 *
 * Bewusst ein EIGENER Port neben {@see ExpressionEvaluatorInterface} und nicht
 * eine Methode darin: wer einen eigenen Evaluator mitbringt, soll ihn nicht
 * erweitern muessen, nur damit der Validator laeuft. Der Validator nimmt den
 * Pruefer optional; ohne ihn bleibt alles wie zuvor.
 */
interface ExpressionCheckerInterface
{
    /**
     * @throws \WorkflowEngine\Exception\ExpressionException wenn der Ausdruck
     *         syntaktisch falsch ist oder unbekannte Variablen benutzt
     */
    public function check(string $expression): void;
}
