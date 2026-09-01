<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\SubWorkflowAction;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\EventPayloadPolicy;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Verknuepfte Workflows: ein Schritt stoesst per start_workflow-Action einen
 * anderen Workflow an — entweder feuer-und-vergiss oder mit Warten auf Abschluss.
 */
#[CoversClass(SubWorkflowAction::class)]
#[CoversClass(WorkflowEngine::class)]
final class SubWorkflowTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();

        $actions = new ActionRegistry();
        $actions->register('start_workflow', new SubWorkflowAction(fn (): WorkflowEngine => $this->engine));

        $this->engine = new WorkflowEngine($this->repo, $actions, new SymfonyExpressionEvaluator());
    }

    public function testFireAndForgetStartsChildAndParentContinues(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childAutomatic()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray(
            $this->parent(['workflowId' => 'child', 'waitForCompletion' => false])
        ));

        $parent = $this->engine->start('parent', ['user' => 'mara']);

        // Eltern laeuft sofort durch.
        self::assertSame(WorkflowInstance::COMPLETED, $parent->status);
        // Genau ein Kind wurde gestartet und ist ebenfalls fertig.
        $children = $this->childrenOf($parent->id);
        self::assertCount(1, $children);
        self::assertSame(WorkflowInstance::COMPLETED, $children[0]->status);
        // Kind erbt den Eltern-Kontext.
        self::assertSame('mara', $children[0]->context['user'] ?? null);
        // Referenz im Eltern-Kontext hinterlegt.
        self::assertIsArray($parent->context['startedWorkflow'] ?? null);
    }

    public function testWaitForCompletionWithSynchronousChildContinuesImmediately(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childAutomatic()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray(
            $this->parent(['workflowId' => 'child', 'waitForCompletion' => true])
        ));

        $parent = $this->engine->start('parent', ['user' => 'mara']);

        // Kind ist rein automatisch -> synchron fertig -> Eltern laeuft direkt weiter.
        self::assertSame(WorkflowInstance::COMPLETED, $parent->status);
        self::assertSame('done', $parent->currentStep);
        $sub = $parent->context['subWorkflow'] ?? null;
        self::assertIsArray($sub);
        $status = $sub['status'] ?? null;
        self::assertIsString($status);
        self::assertSame(WorkflowInstance::COMPLETED, $status);
    }

    public function testWaitForCompletionSuspendsUntilChildFinishes(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childInteractive()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray(
            $this->parent(['workflowId' => 'child', 'waitForCompletion' => true])
        ));

        $parent = $this->engine->start('parent', ['user' => 'mara']);

        // Kind haelt an einem interaktiven Schritt -> Eltern wartet.
        self::assertSame(WorkflowInstance::WAITING_EVENT, $parent->status);
        self::assertSame('call_child', $parent->currentStep);

        $child = $this->childrenOf($parent->id)[0];
        self::assertSame(WorkflowInstance::WAITING_EVENT, $child->status);

        // Kind abschliessen -> Eltern wird geweckt und laeuft zu Ende.
        $this->engine->handleEvent($child->id, 'submit', ['ok' => true]);

        // Frisch laden: die Instanzen wurden durch handleEvent mutiert (fuer PHPStan
        // unsichtbar), sonst gilt der vorherige WAITING_EVENT-Typ fort.
        self::assertSame(WorkflowInstance::COMPLETED, $this->reload($child->id)->status);

        $reloaded = $this->reload($parent->id);
        self::assertSame(WorkflowInstance::COMPLETED, $reloaded->status);
        self::assertSame('done', $reloaded->currentStep);
        $sub = $reloaded->context['subWorkflow'] ?? null;
        self::assertIsArray($sub);
        $status = $sub['status'] ?? null;
        self::assertIsString($status);
        self::assertSame(WorkflowInstance::COMPLETED, $status);
    }

    /**
     * GEMELDET: «wenn der Workflow einen anderen Workflow startet und dieser
     * schliesst, wird der naechste Schritt im Eltern-Ablauf uebersprungen.»
     *
     * Die Ursache stand in der Definition: der Uebergang aus dem
     * Workflow-Schritt trug ein `event`. Ein automatischer Schritt bekommt nie
     * einen Knopfdruck — die Engine suchte den ereignislosen Weg, fand keinen,
     * hielt das fuer «nichts mehr zu tun» und setzte den GANZEN Eltern-Ablauf
     * auf `completed`. Die restlichen Schritte liefen nie.
     *
     * Ein Ablauf, der mitten drin still endet, ist der schlimmste der
     * moeglichen Ausgaenge: nichts zeigt an, dass etwas fehlt. Er scheitert
     * jetzt sichtbar und benennt den Schritt.
     */
    public function testAnAutomaticStepWhoseOnlyExitNeedsAnEventFailsLoudly(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childInteractive()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'parent',
            'startStep' => 'call_child',
            'steps' => [
                'call_child' => [
                    'type' => 'automatic',
                    'action' => 'start_workflow',
                    'config' => ['workflowId' => 'child', 'waitForCompletion' => true],
                    // Genau der gemeldete Fall: der einzige Ausgang verlangt ein Ereignis.
                    'transitions' => [['to' => 'weiter', 'event' => 'submit']],
                ],
                'weiter' => [
                    'type' => 'interactive',
                    'ui' => ['events' => ['submit']],
                    'transitions' => [['to' => 'done', 'event' => 'submit']],
                ],
                'done' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));

        $parent = $this->engine->start('parent');
        $child = $this->childrenOf($parent->id)[0];

        $this->engine->handleEvent($child->id, 'submit', ['ok' => true]);

        $frisch = $this->reload($parent->id);
        self::assertSame(WorkflowInstance::FAILED, $frisch->status, 'Der Ablauf endete still statt zu scheitern.');
        self::assertSame('call_child', $frisch->currentStep, 'Der Schritt, an dem es haengt, muss stehen bleiben.');
        self::assertStringContainsString('call_child', (string) $frisch->lastError);
    }

    /**
     * Dieselbe Luecke ohne Kind-Workflow: ein gewoehnlicher automatischer
     * Schritt, dessen einziger Ausgang ein Ereignis verlangt. Er wurde
     * genauso still zum Ende des Ablaufs.
     */
    public function testTheSameHoleInAPlainAutomaticStep(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'a',
            'steps' => [
                'a' => ['type' => 'automatic', 'transitions' => [['to' => 'b', 'event' => 'submit']]],
                'b' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));

        $instance = $this->engine->start('flow');

        self::assertSame(WorkflowInstance::FAILED, $instance->status);
        self::assertSame('a', $instance->currentStep);
    }

    /** Die Gegenprobe: ohne Uebergaenge ist der Schritt ein Ende, kein Fehler. */
    public function testAStepWithoutTransitionsStillCompletes(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'a',
            'steps' => ['a' => ['type' => 'automatic', 'transitions' => []]],
        ]));

        self::assertSame(WorkflowInstance::COMPLETED, $this->engine->start('flow')->status);
    }

    /**
     * Und die zweite Gegenprobe: eine Bedingung, die gerade nicht zutrifft,
     * ist KEIN Fehler in der Definition — sie ist eine Verzweigung, die
     * diesmal nicht genommen wird. Auch das endet den Ablauf.
     */
    public function testAnUnmetConditionStillCompletes(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'flow',
            'startStep' => 'a',
            'steps' => [
                'a' => ['type' => 'automatic', 'transitions' => [['to' => 'b', 'when' => "context['x'] == true"]]],
                'b' => ['type' => 'automatic', 'transitions' => []],
            ],
        ]));

        self::assertSame(WorkflowInstance::COMPLETED, $this->engine->start('flow', ['x' => false])->status);
    }

    /**
     * Die wichtigste Regressionsgefahr der Payload-Grenze (ADR 0006): der Filter
     * sitzt an der EVENT-Grenze, nicht in `mergeContext()`. Die Verknuepfung
     * zweier Workflows laeuft aber ueber genau die internen Schluessel, die er
     * verwirft (`__awaitWorkflow`, `__parent`) — nur kommen die aus einem
     * Action-Ergebnis, nicht aus einem Payload.
     *
     * Geprueft mit der schaerfsten Policy: greift der Filter zu weit, bleibt der
     * Eltern-Workflow fuer immer stehen.
     */
    public function testWaitForCompletionAlsoWorksUnderEnforcePolicy(): void
    {
        $actions = new ActionRegistry();
        $actions->register('start_workflow', new SubWorkflowAction(fn (): WorkflowEngine => $this->engine));
        $this->engine = new WorkflowEngine(
            $this->repo,
            $actions,
            new SymfonyExpressionEvaluator(),
            eventPayloadPolicy: EventPayloadPolicy::Enforce,
        );

        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childInteractive()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray(
            $this->parent(['workflowId' => 'child', 'waitForCompletion' => true])
        ));

        $parent = $this->engine->start('parent', ['user' => 'mara']);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $parent->status);

        $child = $this->childrenOf($parent->id)[0];
        $this->engine->handleEvent($child->id, 'submit');

        self::assertSame(WorkflowInstance::COMPLETED, $this->reload($child->id)->status);

        $reloaded = $this->reload($parent->id);
        self::assertSame(WorkflowInstance::COMPLETED, $reloaded->status, 'Der Eltern-Workflow wurde nicht geweckt.');
        self::assertSame('done', $reloaded->currentStep);
    }

    public function testParentCanBranchOnChildResult(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->childInteractive()));
        $this->repo->addDefinition(WorkflowDefinition::fromArray($this->parentBranching()));

        $parent = $this->engine->start('parent', []);
        $child = $this->childrenOf($parent->id)[0];

        // Kind liefert grade=gold in den Kontext -> Eltern verzweigt nach 'gold'.
        $this->engine->handleEvent($child->id, 'submit', ['grade' => 'gold']);

        self::assertSame('gold', $parent->currentStep);
        self::assertSame(WorkflowInstance::COMPLETED, $parent->status);
    }

    /**
     * Laedt eine Instanz frisch aus dem Repository (garantiert non-null).
     */
    private function reload(string $id): WorkflowInstance
    {
        $instance = $this->repo->findInstance($id);
        self::assertNotNull($instance);

        return $instance;
    }

    /**
     * @return list<WorkflowInstance>
     */
    private function childrenOf(string $parentId): array
    {
        $children = [];
        foreach ($this->repo->allInstances() as $instance) {
            if ($instance->id !== $parentId) {
                $children[] = $instance;
            }
        }

        return $children;
    }

    /**
     * @return array<string,mixed>
     */
    private function childAutomatic(): array
    {
        return [
            'id' => 'child',
            'startStep' => 'go',
            'steps' => [
                'go' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function childInteractive(): array
    {
        return [
            'id' => 'child',
            'startStep' => 'ask',
            'steps' => [
                'ask' => [
                    'type' => 'interactive',
                    'ui' => ['events' => ['submit']],
                    'transitions' => [['to' => 'end', 'event' => 'submit']],
                ],
                'end' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $config
     *
     * @return array<string,mixed>
     */
    private function parent(array $config): array
    {
        return [
            'id' => 'parent',
            'startStep' => 'call_child',
            'steps' => [
                'call_child' => [
                    'type' => 'automatic',
                    'action' => 'start_workflow',
                    'config' => $config,
                    'transitions' => [['to' => 'done']],
                ],
                'done' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parentBranching(): array
    {
        return [
            'id' => 'parent',
            'startStep' => 'call_child',
            'steps' => [
                'call_child' => [
                    'type' => 'automatic',
                    'action' => 'start_workflow',
                    'config' => ['workflowId' => 'child', 'waitForCompletion' => true],
                    'transitions' => [
                        ['to' => 'gold', 'when' => "context['subWorkflow']['context']['grade'] == 'gold'"],
                        ['to' => 'silver'],
                    ],
                ],
                'gold' => ['type' => 'automatic', 'transitions' => []],
                'silver' => ['type' => 'automatic', 'transitions' => []],
            ],
        ];
    }
}
