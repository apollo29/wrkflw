<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Contracts\ExpressionCheckerInterface;
use WorkflowEngine\Exception\ExpressionException;
use WorkflowEngine\Exception\InvalidDefinitionException;

/**
 * Prueft eine bereits geparste WorkflowDefinition auf graph-semantische Fehler:
 *
 *  - der Start-Step existiert,
 *  - alle Transition-Ziele ('to') verweisen auf existierende Steps,
 *  - es gibt keine unerreichbaren Steps,
 *  - kein erreichbarer Step sitzt in einem Zyklus ohne Ausgang
 *    (jeder erreichbare Step muss einen Endzustand erreichen koennen),
 *  - kein nicht-interaktiver Step haengt an lauter Ereignis-Uebergaengen,
 *  - jede Bedingung (`when`) und jeder Timer-Ausdruck (`until`) laesst sich
 *    uebersetzen — sofern ein {@see ExpressionCheckerInterface} uebergeben wird.
 *
 * Alle gefundenen Fehler werden gesammelt und gebuendelt geworfen.
 */
final class DefinitionValidator
{
    /**
     * @param ExpressionCheckerInterface|null $checker prueft `when` und `until`
     *        beim Speichern. Optional, damit eine Anwendung mit eigenem
     *        Evaluator (und eigenen Wurzeln) den Validator unveraendert
     *        weiterbenutzen kann — ohne ihn bleibt alles wie zuvor.
     */
    public function __construct(private readonly ?ExpressionCheckerInterface $checker = null)
    {
    }

    /**
     * @throws InvalidDefinitionException wenn die Definition ungueltig ist
     */
    public function validate(WorkflowDefinition $def): void
    {
        $errors = [];

        $this->checkTransitionTargets($def, $errors);
        $this->checkEventlessExit($def, $errors);
        $this->checkExpressions($def, $errors);
        $startExists = $def->hasStep($def->startStep);
        if (!$startExists) {
            $errors[] = "Start-Step '{$def->startStep}' existiert nicht.";
        }

        if ($startExists) {
            $reachable = $this->reachableSteps($def);
            $this->checkUnreachable($def, $reachable, $errors);
            $this->checkExitReachable($def, $reachable, $errors);
        }

        if ($errors !== []) {
            throw new InvalidDefinitionException(array_values(array_unique($errors)));
        }
    }

    /**
     * Laesst sich jede Bedingung uebersetzen?
     *
     * GEMELDET: `"when": "daten_korrekt == true"` — gemeint war
     * `context['daten_korrekt']`. Die Sprache kennt nur die Wurzeln `context`
     * und `now`; ein blosser Name ist ein Fehler. Beim AUSWERTEN faellt das
     * erst auf, wenn jemand den Knopf drueckt — als Serverfehler auf der
     * oeffentlichen Seite, mitten im Ablauf.
     *
     * @param list<string> $errors
     */
    private function checkExpressions(WorkflowDefinition $def, array &$errors): void
    {
        if ($this->checker === null) {
            return;
        }

        foreach ($def->steps as $name => $step) {
            foreach ($step->transitions as $t) {
                try {
                    $this->checker->check($t->when);
                } catch (ExpressionException $e) {
                    $errors[] = "Step '{$name}', Uebergang nach '{$t->to}': {$e->getMessage()}";
                }
            }

            if ($step->untilExpr !== null) {
                try {
                    $this->checker->check($step->untilExpr);
                } catch (ExpressionException $e) {
                    $errors[] = "Step '{$name}', 'until': {$e->getMessage()}";
                }
            }
        }
    }

    /**
     * Ein nicht-interaktiver Step, dessen saemtliche Uebergaenge ein Ereignis
     * verlangen, ist eine Sackgasse.
     *
     * GEMELDET: ein Schritt «starte anderen Workflow, warte auf Abschluss»,
     * dessen einziger Uebergang `"event": "submit"` trug. Automatische und
     * Timer-Schritte bekommen nie einen Knopfdruck — die Engine fand keinen
     * Weg hinaus und hielt das fuer das Ende des Ablaufs. Die restlichen
     * Schritte liefen nie, und nichts zeigte an, dass etwas fehlte.
     *
     * Die Engine scheitert seitdem sichtbar daran; hier faellt es frueher auf,
     * beim Speichern. KEINE Uebergaenge bleibt erlaubt: das ist ein Endschritt
     * und keine Sackgasse.
     *
     * @param list<string> $errors
     */
    private function checkEventlessExit(WorkflowDefinition $def, array &$errors): void
    {
        foreach ($def->steps as $name => $step) {
            if ($step->isInteractive() || $step->transitions === []) {
                continue;
            }

            foreach ($step->transitions as $t) {
                if ($t->event === null) {
                    continue 2;
                }
            }

            $errors[] = "Step '{$name}' hat keinen Uebergang ohne Ereignis. Ein Schritt vom Typ "
                . "'{$step->type}' bekommt nie einen Knopfdruck und kaeme nicht weiter.";
        }
    }

    /**
     * @param list<string> $errors
     */
    private function checkTransitionTargets(WorkflowDefinition $def, array &$errors): void
    {
        foreach ($def->steps as $name => $step) {
            foreach ($step->transitions as $t) {
                if (!$def->hasStep($t->to)) {
                    $errors[] = "Step '{$name}' verweist auf unbekanntes Ziel '{$t->to}'.";
                }
            }
        }
    }

    /**
     * @param array<string,true> $reachable
     * @param list<string>        $errors
     */
    private function checkUnreachable(WorkflowDefinition $def, array $reachable, array &$errors): void
    {
        foreach ($def->steps as $name => $step) {
            if (!isset($reachable[$name])) {
                $errors[] = "Step '{$name}' ist vom Start aus nicht erreichbar.";
            }
        }
    }

    /**
     * @param array<string,true> $reachable
     * @param list<string>        $errors
     */
    private function checkExitReachable(WorkflowDefinition $def, array $reachable, array &$errors): void
    {
        $canReachEnd = $this->stepsThatCanReachTerminal($def);

        foreach (array_keys($reachable) as $name) {
            if (!isset($canReachEnd[$name])) {
                $errors[] = "Step '{$name}' kann keinen Endzustand erreichen (Zyklus ohne Ausgang).";
            }
        }
    }

    /**
     * Vorwaerts-Erreichbarkeit ab dem Start-Step (ueber alle Transitionen,
     * unabhaengig von Event-Bindung). Nur existierende Ziele werden verfolgt.
     *
     * @return array<string,true>
     */
    private function reachableSteps(WorkflowDefinition $def): array
    {
        $reachable = [];
        $queue = [$def->startStep];

        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($reachable[$name])) {
                continue;
            }
            $reachable[$name] = true;

            foreach ($def->step($name)->transitions as $t) {
                if ($def->hasStep($t->to) && !isset($reachable[$t->to])) {
                    $queue[] = $t->to;
                }
            }
        }

        return $reachable;
    }

    /**
     * Rueckwaerts-Erreichbarkeit von den Endzustaenden (Steps ohne Transitionen):
     * alle Steps, von denen aus ein Endzustand erreichbar ist.
     *
     * @return array<string,true>
     */
    private function stepsThatCanReachTerminal(WorkflowDefinition $def): array
    {
        // Vorgaenger-Graph aufbauen (nur ueber existierende Ziele).
        $predecessors = [];
        $terminals = [];
        foreach ($def->steps as $name => $step) {
            if ($step->isTerminal()) {
                $terminals[] = $name;
                continue;
            }
            foreach ($step->transitions as $t) {
                if ($def->hasStep($t->to)) {
                    $predecessors[$t->to][] = $name;
                }
            }
        }

        $canReach = [];
        $queue = $terminals;
        while ($queue !== []) {
            $name = array_shift($queue);
            if (isset($canReach[$name])) {
                continue;
            }
            $canReach[$name] = true;

            foreach ($predecessors[$name] ?? [] as $pred) {
                if (!isset($canReach[$pred])) {
                    $queue[] = $pred;
                }
            }
        }

        return $canReach;
    }
}
