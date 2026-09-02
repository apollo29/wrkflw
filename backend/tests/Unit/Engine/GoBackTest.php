<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SubWorkflowAction;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Ein Schritt zurueck.
 *
 * GEMELDET: «Ein Trainer kreuzt an, er haette ein UEFA-Zertifikat. Auf der
 * Upload-Maske laedt er es hoch, und es kommt die Meldung, dass das Zertifikat
 * — warum auch immer — nicht korrekt ist. Er ist anschliessend darin gefangen
 * und kommt nicht weiter.»
 *
 * Das Tueckische daran: eine abgelehnte Datei loest bewusst KEINEN Uebergang
 * aus (der Schritt soll offen bleiben, damit man es nochmal versuchen kann).
 * Wer aber gar kein gueltiges Zertifikat hat, bleibt genau deshalb stehen —
 * und der Haken, der ihn hierher gebracht hat, sitzt einen Schritt frueher.
 *
 * ## Die drei Festlegungen
 *
 * 1. **Zurueck heisst: zum letzten INTERAKTIVEN Schritt.** Nicht zum
 *    unmittelbar vorherigen: der kann automatisch sein, und dann liefe seine
 *    Aktion ein zweites Mal — eine zweite Mail, ein zweiter Kind-Ablauf.
 *    Gemeint ist «die vorherige Maske», nicht «fuehre das nochmal aus».
 * 2. **Nur wo der Schritt es erlaubt** (`ui.back: true`). Zurueckgehen macht
 *    bereits Geschehenes nicht rueckgaengig; ob das vertretbar ist, weiss nur,
 *    wer den Ablauf gebaut hat. Vorgabe ist deshalb AUS.
 * 3. **Ueber die Ablauf-Grenze hinweg.** Steht die Person in einem
 *    Kind-Ablauf, an dessen Anfang, wird das Kind ABGEBROCHEN und der Eltern-
 *    Ablauf geht seinerseits zurueck. Ohne das waere der gemeldete Fall nicht
 *    geloest: der Upload ist ein eigener Ablauf, der Haken steht im Eltern.
 *
 * Der Kontext bleibt, wie er ist: zurueckgehen bewegt die Position, nicht die
 * Daten. Ein Formular fuellt sich beim Anzeigen ohnehin leer.
 */
#[CoversClass(WorkflowEngine::class)]
final class GoBackTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $actions = new ActionRegistry();
        $actions->register('start_workflow', new SubWorkflowAction(fn (): WorkflowEngine => $this->engine));
        $actions->register('noop', $this->noop());
        $this->engine = new WorkflowEngine($this->repo, $actions, new SymfonyExpressionEvaluator());
    }

    // ------------------------------------------------ innerhalb eines Ablaufs

    public function testBackReturnsToThePreviousInteractiveStep(): void
    {
        $this->addFlow();
        $instance = $this->engine->start('flow');
        $this->engine->handleEvent($instance->id, 'submit', ['ok' => true]);
        self::assertSame('zweite', $instance->currentStep);

        $this->engine->goBack($instance->id);

        self::assertSame('erste', $this->reload($instance->id)->currentStep);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $this->reload($instance->id)->status);
        self::assertContains('back', $this->repo->historyKinds());
    }

    /**
     * Der automatische Schritt DAZWISCHEN wird uebersprungen — sonst liefe
     * seine Aktion ein zweites Mal.
     */
    public function testAnAutomaticStepInBetweenIsSkipped(): void
    {
        $this->addFlowWithAutomaticStep();
        $instance = $this->engine->start('flow');
        $this->engine->handleEvent($instance->id, 'submit');
        self::assertSame('zweite', $instance->currentStep);

        $this->engine->goBack($instance->id);

        self::assertSame('erste', $this->reload($instance->id)->currentStep);
    }

    /** Ohne `ui.back` geht nichts — die Vorgabe ist AUS. */
    public function testWithoutTheFlagBackIsRefused(): void
    {
        $this->addFlow(erlaubt: false);
        $instance = $this->engine->start('flow');
        $this->engine->handleEvent($instance->id, 'submit');

        self::assertFalse($this->engine->canGoBack($this->reload($instance->id)));

        $this->expectException(WorkflowException::class);
        $this->engine->goBack($instance->id);
    }

    /** Am ersten Schritt gibt es kein Zurueck. */
    public function testAtTheFirstStepThereIsNoWayBack(): void
    {
        $this->addFlow();
        $instance = $this->engine->start('flow');

        self::assertFalse($this->engine->canGoBack($instance));
    }

    // ------------------------------------------- ueber die Ablauf-Grenze

    /**
     * Der gemeldete Fall: die Person steht im Kind-Ablauf (Upload), und der
     * Haken, der sie hergebracht hat, sitzt im Eltern.
     */
    public function testFromAChildTheParentGoesBackAndTheChildIsCancelled(): void
    {
        $this->addChildFlow();
        $this->addParentFlow();

        $parent = $this->engine->start('parent');
        $this->engine->handleEvent($parent->id, 'submit', ['uefa' => true]);

        $kind = $this->childrenOf($parent->id)[0];
        self::assertSame('hochladen', $kind->currentStep);
        self::assertTrue($this->engine->canGoBack($kind));

        $this->engine->goBack($kind->id);

        // Der Eltern steht wieder auf seiner Frage …
        $frisch = $this->reload($parent->id);
        self::assertSame('nachweise', $frisch->currentStep);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $frisch->status);
        // … und wartet nicht mehr auf das Kind.
        self::assertArrayNotHasKey('__awaitWorkflow', $frisch->context);

        // Das Kind ist abgebrochen — nicht «fertig», das waere gelogen.
        self::assertSame(WorkflowInstance::CANCELLED, $this->reload($kind->id)->status);
        self::assertTrue($this->reload($kind->id)->isFinished());
    }

    /** Und danach laesst sich derselbe Weg erneut gehen. */
    public function testAfterGoingBackTheFlowCanRunAgain(): void
    {
        $this->addChildFlow();
        $this->addParentFlow();

        $parent = $this->engine->start('parent');
        $this->engine->handleEvent($parent->id, 'submit', ['uefa' => true]);
        $erstesKind = $this->childrenOf($parent->id)[0];
        $this->engine->goBack($erstesKind->id);

        $this->engine->handleEvent($parent->id, 'submit', ['uefa' => true]);

        $kinder = $this->childrenOf($parent->id);
        self::assertCount(2, $kinder, 'Es entsteht ein frischer Kind-Ablauf.');
        self::assertSame(WorkflowInstance::CANCELLED, $this->reload($erstesKind->id)->status);
    }

    /** Ein abgebrochenes Kind weckt den Eltern NICHT — der ist ja schon weiter. */
    public function testACancelledChildDoesNotWakeTheParent(): void
    {
        $this->addChildFlow();
        $this->addParentFlow();

        $parent = $this->engine->start('parent');
        $this->engine->handleEvent($parent->id, 'submit', ['uefa' => true]);
        $kind = $this->childrenOf($parent->id)[0];

        $this->engine->goBack($kind->id);

        // Waere der Eltern geweckt worden, stuende er auf 'fertig'.
        self::assertSame('nachweise', $this->reload($parent->id)->currentStep);
    }

    // ------------------------------------------------------------- Hilfsmittel

    private function reload(string $id): WorkflowInstance
    {
        $i = $this->repo->findInstance($id);
        self::assertNotNull($i);

        return $i;
    }

    /** @return list<WorkflowInstance> */
    private function childrenOf(string $parentId): array
    {
        $out = [];
        foreach ($this->repo->allInstances() as $i) {
            $link = $i->context['__parent'] ?? null;
            if (is_array($link) && ($link['instanceId'] ?? null) === $parentId) {
                $out[] = $i;
            }
        }

        return $out;
    }

    private function addFlow(bool $erlaubt = true): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'erste',
            'steps' => [
                'erste' => [
                    'type' => 'interactive',
                    'transitions' => [['to' => 'zweite', 'event' => 'submit']],
                ],
                'zweite' => [
                    'type' => 'interactive',
                    'ui' => $erlaubt ? ['back' => true] : [],
                    'transitions' => [['to' => 'fertig', 'event' => 'submit']],
                ],
                'fertig' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));
    }

    private function addFlowWithAutomaticStep(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'erste',
            'steps' => [
                'erste' => ['type' => 'interactive', 'transitions' => [['to' => 'dazwischen', 'event' => 'submit']]],
                'dazwischen' => ['type' => 'automatic', 'action' => 'noop', 'transitions' => [['to' => 'zweite']]],
                'zweite' => [
                    'type' => 'interactive',
                    'ui' => ['back' => true],
                    'transitions' => [['to' => 'fertig', 'event' => 'submit']],
                ],
                'fertig' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));
    }

    private function addParentFlow(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'parent',
            'startStep' => 'nachweise',
            'steps' => [
                'nachweise' => [
                    'type' => 'interactive',
                    'ui' => ['fields' => [['name' => 'uefa', 'type' => 'boolean']]],
                    'transitions' => [['to' => 'upload', 'event' => 'submit']],
                ],
                'upload' => [
                    'type' => 'automatic',
                    'action' => 'start_workflow',
                    'config' => ['workflowId' => 'child', 'waitForCompletion' => true],
                    'transitions' => [['to' => 'fertig']],
                ],
                'fertig' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));
    }

    private function addChildFlow(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'child',
            'startStep' => 'hochladen',
            'steps' => [
                'hochladen' => [
                    'type' => 'interactive',
                    'ui' => ['back' => true, 'fields' => [['name' => 'datei', 'type' => 'file']]],
                    'transitions' => [['to' => 'fertig', 'event' => 'submit']],
                ],
                'fertig' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));
    }

    private function noop(): ActionInterface
    {
        return new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                return [];
            }
        };
    }
}
