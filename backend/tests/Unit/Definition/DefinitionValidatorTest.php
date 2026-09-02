<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Exception\InvalidDefinitionException;

#[CoversClass(DefinitionValidator::class)]
final class DefinitionValidatorTest extends TestCase
{
    private DefinitionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new DefinitionValidator();
    }

    /** @param array<string,mixed> $steps */
    private function def(string $startStep, array $steps): WorkflowDefinition
    {
        return WorkflowDefinition::fromArray([
            'id' => 'test',
            'startStep' => $startStep,
            'steps' => $steps,
        ]);
    }

    public function testValidOnboardingPasses(): void
    {
        $path = dirname(__DIR__, 3) . '/examples/onboarding.json';
        $json = file_get_contents($path);
        self::assertIsString($json);
        /** @var array<string,mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $def = WorkflowDefinition::fromArray($data);

        // Wirft keine Exception -> Definition ist gueltig.
        $this->validator->validate($def);
    }

    public function testStartStepNotInStepsThrows(): void
    {
        $def = $this->def('nope', ['a' => ['transitions' => []]]);

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($def);
    }

    public function testUnknownTransitionTargetThrows(): void
    {
        $def = $this->def('a', [
            'a' => ['transitions' => [['to' => 'ghost']]],
        ]);

        try {
            $this->validator->validate($def);
            self::fail('Erwartete InvalidDefinitionException');
        } catch (InvalidDefinitionException $e) {
            self::assertNotEmpty($e->errors());
        }
    }

    public function testUnreachableStepThrows(): void
    {
        $def = $this->def('a', [
            'a' => ['transitions' => []],            // Endzustand
            'orphan' => ['transitions' => []],        // nie erreichbar
        ]);

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($def);
    }

    public function testCycleWithoutExitThrows(): void
    {
        // a -> b -> a, kein Endzustand erreichbar
        $def = $this->def('a', [
            'a' => ['transitions' => [['to' => 'b']]],
            'b' => ['transitions' => [['to' => 'a']]],
        ]);

        $this->expectException(InvalidDefinitionException::class);
        $this->validator->validate($def);
    }

    public function testEventTransitionsAreFollowedForReachability(): void
    {
        // erreichbar nur ueber eine Event-Transition -> darf nicht als unerreichbar gelten
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'transitions' => [['event' => 'submit', 'to' => 'b']]],
            'b' => ['transitions' => []],
        ]);

        $this->validator->validate($def);
        $this->expectNotToPerformAssertions();
    }

    /**
     * GEMELDET: ein Workflow-Schritt («starte anderen Workflow, warte auf
     * Abschluss»), dessen einziger Uebergang `"event": "submit"` trug. Ein
     * nicht-interaktiver Schritt bekommt nie einen Knopfdruck; der Ablauf
     * endete deshalb still mitten drin, statt weiterzulaufen.
     *
     * Die Engine scheitert seitdem sichtbar daran. Hier faellt es frueher auf:
     * beim Speichern im Editor, bevor jemand den Ablauf startet.
     */
    public function testAnAutomaticStepWhoseOnlyExitNeedsAnEventThrows(): void
    {
        $def = $this->def('a', [
            'a' => ['transitions' => [['to' => 'b', 'event' => 'submit']]],
            'b' => ['transitions' => []],
        ]);

        try {
            $this->validator->validate($def);
            self::fail('Erwartete InvalidDefinitionException');
        } catch (InvalidDefinitionException $e) {
            self::assertStringContainsString('a', implode(' ', $e->errors()));
        }
    }

    /**
     * GEMELDET: «bei mir erscheint kein Button.»
     *
     * Die andere Richtung derselben Sackgasse. Ein interaktiver Schritt WARTET
     * auf ein Ereignis; traegt keiner seiner Uebergaenge eines, entsteht kein
     * Knopf und der Ablauf steht dort fuer immer. Die oeffentliche Seite leitet
     * ihre Knoepfe aus den UEBERGAENGEN ab — `ui.events` ist reine Zierde, und
     * genau das verleitet dazu, es dort zu deklarieren und am Uebergang zu
     * vergessen.
     */
    public function testAnInteractiveStepWithoutAnyEventTransitionThrows(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'ui' => ['events' => ['submit']], 'transitions' => [['to' => 'b']]],
            'b' => ['transitions' => []],
        ]);

        try {
            $this->validator->validate($def);
            self::fail('Erwartete InvalidDefinitionException');
        } catch (InvalidDefinitionException $e) {
            self::assertStringContainsString('Ereignis', implode(' ', $e->errors()));
        }
    }

    /**
     * Ein interaktiver Schritt OHNE Uebergaenge ist dagegen ein Endschritt und
     * bleibt erlaubt — dieselbe Ausnahme wie bei den automatischen.
     */
    public function testAnInteractiveEndStepIsFine(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'transitions' => []],
        ]);

        $this->expectNotToPerformAssertions();
        $this->validator->validate($def);
    }

    /** Ein interaktiver Schritt lebt von Ereignissen — dort ist es richtig so. */
    public function testAnInteractiveStepMayHaveOnlyEventTransitions(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'transitions' => [['to' => 'b', 'event' => 'submit']]],
            'b' => ['transitions' => []],
        ]);

        // Wirft keine Exception -> Definition ist gueltig.
        $this->expectNotToPerformAssertions();
        $this->validator->validate($def);
    }

    /**
     * Und die Mischung ist der eigentliche Sinn der Regel: ein Timer-Schritt
     * darf einen Ereignis-Ausgang haben, solange er auch ohne einen
     * weiterkommt — sonst bliebe er stehen, wenn die Zeit abgelaufen ist.
     */
    public function testAMixOfEventAndEventlessExitsIsFine(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'timer', 'delaySeconds' => 60, 'transitions' => [
                ['to' => 'b', 'event' => 'abbrechen'],
                ['to' => 'b'],
            ]],
            'b' => ['transitions' => []],
        ]);

        // Wirft keine Exception -> Definition ist gueltig.
        $this->expectNotToPerformAssertions();
        $this->validator->validate($def);
    }

    // ------------------------------------------------ Bedingungen (Ausdruecke)

    /**
     * GEMELDET, aus einem im Editor gebauten Ablauf:
     *
     *     "when": "daten_korrekt == true"
     *
     * Gemeint war `context['daten_korrekt']`. Die ExpressionLanguage kennt nur
     * die Wurzeln `context` und `now`; ein blosser Name ist keine Variable,
     * sondern ein Fehler — und zwar einer, der erst beim Klick auf «Absenden»
     * auffliegt, als 500er auf der oeffentlichen Seite.
     *
     * Mit einem Pruefer faellt es beim Speichern auf, wo der Autor noch
     * danebensteht.
     */
    public function testAnUnknownVariableInAConditionThrows(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'transitions' => [
                ['to' => 'b', 'event' => 'submit', 'when' => 'daten_korrekt == true'],
            ]],
            'b' => ['transitions' => []],
        ]);

        try {
            $this->mitPruefer()->validate($def);
            self::fail('Erwartete InvalidDefinitionException');
        } catch (InvalidDefinitionException $e) {
            $text = implode(' ', $e->errors());
            self::assertStringContainsString('a', $text);
            self::assertStringContainsString('daten_korrekt', $text);
        }
    }

    /** Die richtige Schreibweise geht durch. */
    public function testTheCorrectSpellingPasses(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'transitions' => [
                ['to' => 'b', 'event' => 'submit', 'when' => "context['daten_korrekt'] == true"],
            ]],
            'b' => ['transitions' => []],
        ]);

        $this->expectNotToPerformAssertions();
        $this->mitPruefer()->validate($def);
    }

    /** Auch der Timer-Ausdruck wird geprueft — dieselbe Falle, andere Stelle. */
    public function testATimerExpressionIsCheckedToo(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'timer', 'until' => 'faelligkeit + days(3)', 'transitions' => [['to' => 'b']]],
            'b' => ['transitions' => []],
        ]);

        $this->expectException(InvalidDefinitionException::class);
        $this->mitPruefer()->validate($def);
    }

    /**
     * Ohne Pruefer bleibt alles wie bisher: die Ausdruecke werden nicht
     * angefasst. Das haelt den Validator fuer Anwendungen brauchbar, die einen
     * eigenen Evaluator mit eigenen Wurzeln mitbringen.
     */
    public function testWithoutACheckerExpressionsAreNotTouched(): void
    {
        $def = $this->def('a', [
            'a' => ['type' => 'interactive', 'transitions' => [
                ['to' => 'b', 'event' => 'submit', 'when' => 'daten_korrekt == true'],
            ]],
            'b' => ['transitions' => []],
        ]);

        $this->expectNotToPerformAssertions();
        (new DefinitionValidator())->validate($def);
    }

    private function mitPruefer(): DefinitionValidator
    {
        return new DefinitionValidator(new SymfonyExpressionEvaluator());
    }
}
