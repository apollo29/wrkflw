<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Definition\WorkflowInput;

/**
 * Der deklarierte Startkontext: welche Daten ein Workflow beim Start braucht.
 *
 * WARUM ES DAS GIBT: heute steht nirgends, was eine Definition erwartet. Wer
 * `trainer-onboarding` startet, muss `trainer_id`, `name`, `mail` und `link`
 * mitgeben — das weiss man nur, wenn man die Schritte liest und die
 * Platzhalter zusammensucht. Fehlt etwas, laeuft der Ablauf trotzdem an und
 * verschickt eine Mail an eine leere Adresse.
 *
 * Nicht deklariert heisst weiterhin «keine Pruefung»: bestehende Definitionen
 * bleiben unveraendert gueltig.
 */
#[CoversClass(WorkflowDefinition::class)]
#[CoversClass(WorkflowInput::class)]
final class WorkflowInputTest extends TestCase
{
    /** @param list<array<string,mixed>> $inputs */
    private function definition(array $inputs): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'a',
            'inputs' => $inputs,
            'steps' => ['a' => ['type' => 'automatic', 'transitions' => []]],
        ]);
    }

    public function testADefinitionWithoutInputsDeclaresNothing(): void
    {
        $def = WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'a',
            'steps' => ['a' => ['type' => 'automatic', 'transitions' => []]],
        ]);

        self::assertSame([], $def->inputs);
        self::assertSame([], $def->missingInputs(['irgendwas' => 1]));
        self::assertSame([], $def->missingInputs([]));
    }

    public function testInputsAreReadWithTheirProperties(): void
    {
        $def = $this->definition([
            ['name' => 'trainer_id', 'label' => 'Trainer-ID', 'required' => true, 'beispiel' => 'TR-0123'],
            ['name' => 'kjs_mail', 'label' => 'KJS-Mail'],
        ]);

        self::assertCount(2, $def->inputs);
        self::assertSame('trainer_id', $def->inputs[0]->name);
        self::assertSame('Trainer-ID', $def->inputs[0]->label);
        self::assertTrue($def->inputs[0]->required);
        self::assertSame('TR-0123', $def->inputs[0]->beispiel);

        // Ohne Angabe ist ein Eingabewert NICHT Pflicht. Der umgekehrte
        // Vorgabewert wuerde bestehende Definitionen beim ersten Speichern im
        // Editor scharf schalten, ohne dass jemand das wollte.
        self::assertFalse($def->inputs[1]->required);
        self::assertSame('', $def->inputs[1]->beispiel);
    }

    public function testAnInputWithoutANameIsIgnored(): void
    {
        // Der Editor legt eine leere Zeile an, sobald jemand «+ Feld» drueckt.
        // Eine namenlose Zeile darf den Start nicht blockieren.
        $def = $this->definition([
            ['name' => '', 'required' => true],
            ['name' => '   ', 'required' => true],
            ['label' => 'ohne name', 'required' => true],
        ]);

        self::assertSame([], $def->inputs);
    }

    public function testTheLabelFallsBackToTheName(): void
    {
        $def = $this->definition([['name' => 'mail']]);

        self::assertSame('mail', $def->inputs[0]->label);
    }

    public function testMissingInputsNamesEveryRequiredKeyThatIsAbsent(): void
    {
        $def = $this->definition([
            ['name' => 'trainer_id', 'required' => true],
            ['name' => 'mail', 'required' => true],
            ['name' => 'kjs_mail'],
        ]);

        // Alle fehlenden auf einmal, nicht nur die erste: sonst ist es drei
        // Anlaeufe statt einem.
        self::assertSame(['trainer_id', 'mail'], $def->missingInputs([]));
        self::assertSame(['mail'], $def->missingInputs(['trainer_id' => 'TR-1']));
        self::assertSame([], $def->missingInputs(['trainer_id' => 'TR-1', 'mail' => 'a@b.ch']));
    }

    public function testAnOptionalInputIsNeverMissing(): void
    {
        $def = $this->definition([['name' => 'kjs_mail']]);

        self::assertSame([], $def->missingInputs([]));
    }

    /**
     * Ein Schluessel, der da ist, aber leer: das ist genau der Fall, der heute
     * eine Mail an eine leere Adresse schickt. Er zaehlt als fehlend.
     */
    public function testAnEmptyValueCountsAsMissing(): void
    {
        $def = $this->definition([['name' => 'mail', 'required' => true]]);

        self::assertSame(['mail'], $def->missingInputs(['mail' => '']));
        self::assertSame(['mail'], $def->missingInputs(['mail' => '   ']));
        self::assertSame(['mail'], $def->missingInputs(['mail' => null]));
    }

    /**
     * `false` und `0` sind Werte, keine Luecken. Wer ein Ja/Nein-Feld als
     * Pflicht deklariert, meint «muss gesetzt sein», nicht «muss wahr sein».
     */
    public function testFalseAndZeroAreValues(): void
    {
        $def = $this->definition([['name' => 'flag', 'required' => true]]);

        self::assertSame([], $def->missingInputs(['flag' => false]));
        self::assertSame([], $def->missingInputs(['flag' => 0]));
    }

    /**
     * Die Speicherung braucht KEINE Rueckwandlung: `DefinitionController::save`
     * legt das eingehende Array ab, nicht eine Serialisierung des Objekts.
     * Unbekannte Schluessel — und `inputs` war bis eben einer — ueberleben das
     * unveraendert. Der Test haelt genau diese Eigenschaft fest, weil die
     * ganze Phase auf ihr steht.
     */
    public function testUnknownKeysAreNotStrippedByParsing(): void
    {
        $roh = [
            'id' => 'flow',
            'startStep' => 'a',
            'inputs' => [['name' => 'trainer_id', 'required' => true]],
            'steps' => ['a' => ['type' => 'automatic', 'transitions' => []]],
        ];

        $def = WorkflowDefinition::fromArray($roh);

        self::assertSame('trainer_id', $def->inputs[0]->name);

        // Und der Weg, den die Speicherung wirklich nimmt: `save()` legt
        // json_encode($roh) ab, nicht eine Serialisierung des Objekts.
        $gespeichert = json_decode((string) json_encode($roh), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($gespeichert);
        self::assertSame([['name' => 'trainer_id', 'required' => true]], $gespeichert['inputs'] ?? null);
    }
}
