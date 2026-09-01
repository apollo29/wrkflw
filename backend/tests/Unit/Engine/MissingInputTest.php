<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Exception\MissingInputException;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Ein Start ohne die deklarierten Pflichtangaben scheitert sichtbar.
 *
 * HEUTIGER ZUSTAND, und der Grund fuer diese Phase: fehlt etwas, laeuft der
 * Ablauf trotzdem an. Die Platzhalter bleiben leer, `send_email` verschickt an
 * eine leere Adresse, und auffallen tut es erst, wenn jemand sich meldet. Die
 * Fehlersuche beginnt dann bei der Mail und endet drei Stationen spaeter beim
 * Aufrufer, der einen Schluessel vergessen hat.
 *
 * Sichtbar scheitern heisst: MIT ALLEN fehlenden Namen auf einmal.
 */
#[CoversClass(WorkflowEngine::class)]
#[CoversClass(MissingInputException::class)]
final class MissingInputTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->engine = new WorkflowEngine($this->repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
    }

    /** @param list<array<string,mixed>> $inputs */
    private function definition(array $inputs): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'a',
            'inputs' => $inputs,
            'steps' => ['a' => ['type' => 'interactive', 'transitions' => [['to' => 'b', 'event' => 'go']]],
                        'b' => ['type' => 'automatic', 'transitions' => []]],
        ]));
    }

    public function testAStartWithoutARequiredInputIsRefused(): void
    {
        $this->definition([
            ['name' => 'trainer_id', 'label' => 'Trainer-ID', 'required' => true],
            ['name' => 'mail', 'label' => 'E-Mail', 'required' => true],
        ]);

        try {
            $this->engine->start('flow', ['trainer_id' => 'TR-1']);
            self::fail('Der Start haette scheitern muessen.');
        } catch (MissingInputException $e) {
            self::assertSame(['mail'], $e->missing);
            // Die Meldung nennt Ross und Reiter: welcher Ablauf, welches Feld.
            self::assertStringContainsString('flow', $e->getMessage());
            self::assertStringContainsString('mail', $e->getMessage());
        }
    }

    public function testTheRefusalNamesEveryMissingInputAtOnce(): void
    {
        $this->definition([
            ['name' => 'trainer_id', 'required' => true],
            ['name' => 'mail', 'required' => true],
            ['name' => 'link', 'required' => true],
        ]);

        try {
            $this->engine->start('flow', []);
            self::fail('Der Start haette scheitern muessen.');
        } catch (MissingInputException $e) {
            self::assertSame(['trainer_id', 'mail', 'link'], $e->missing);
        }
    }

    public function testNothingIsWrittenWhenTheStartIsRefused(): void
    {
        // Die Pruefung sitzt VOR dem Anlegen. Sonst bliebe eine Instanz
        // zurueck, die nie laufen kann — und im Protokoll ein Start, den es
        // nicht gab.
        $this->definition([['name' => 'mail', 'required' => true]]);

        try {
            $this->engine->start('flow', []);
        } catch (MissingInputException) {
            // erwartet
        }

        self::assertSame([], $this->repo->allInstances());
    }

    public function testAStartWithEverythingDeclaredGoesThrough(): void
    {
        $this->definition([
            ['name' => 'trainer_id', 'required' => true],
            ['name' => 'kjs_mail'],
        ]);

        $instanz = $this->engine->start('flow', ['trainer_id' => 'TR-1']);

        self::assertSame('a', $instanz->currentStep);
    }

    /**
     * Der Rueckwaertskompatibilitaets-Fall, und der wichtigste: alle
     * bestehenden Definitionen haben keine `inputs`. Sie duerfen sich kein
     * bisschen anders verhalten als vorher.
     */
    public function testADefinitionWithoutInputsStartsWithAnythingOrNothing(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'alt',
            'startStep' => 'a',
            'steps' => ['a' => ['type' => 'interactive', 'transitions' => []]],
        ]));

        self::assertSame('a', $this->engine->start('alt', [])->currentStep);
        self::assertSame('a', $this->engine->start('alt', ['x' => 1])->currentStep);
    }

    /**
     * Ein verknuepfter Workflow geht durch dieselbe Tuer (`startWorkflow`
     * delegiert auf `start`). Er erbt den Kontext des Eltern-Ablaufs — und
     * wenn dort etwas fehlt, ist das genau der Fall, den man frueh sehen will.
     */
    public function testALinkedWorkflowIsCheckedToo(): void
    {
        $this->definition([['name' => 'mail', 'required' => true]]);

        $this->expectException(MissingInputException::class);
        $this->engine->startWorkflow('flow', []);
    }
}
