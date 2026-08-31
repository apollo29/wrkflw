<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\WorkflowStarterInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\EventPayloadPolicy;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\ContextKeys;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Die Grenze zwischen Aussenwelt und Instanz-Kontext (ADR 0006).
 *
 * Ein Event-Payload ist Eingabe eines Aufrufers. Er landete bisher ungefiltert
 * im Kontext — und aus genau diesem Kontext interpoliert `send_email` seinen
 * Empfaenger und liest `check_data` seine Datensatz-ID.
 *
 * Zwei Ebenen, hier getrennt geprueft:
 *   A) Engine-interne Schluessel ("__") werden IMMER verworfen, unabhaengig von
 *      der Policy. Alle A-Tests laufen deshalb mit dem Default.
 *   B) Die Feld-Whitelist aus `ui.fields` ist geschaltet; `Enforce` ist
 *      fail-closed.
 */
#[CoversClass(WorkflowEngine::class)]
#[CoversClass(ContextKeys::class)]
#[CoversClass(EventPayloadPolicy::class)]
final class EventPayloadBoundaryTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    private ActionRegistry $actions;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->actions = new ActionRegistry();
    }

    private function engine(EventPayloadPolicy $policy = EventPayloadPolicy::Allow): WorkflowEngine
    {
        return new WorkflowEngine(
            $this->repo,
            $this->actions,
            new SymfonyExpressionEvaluator(),
            eventPayloadPolicy: $policy,
        );
    }

    // ------------------------------------------------- Ebene A: interne Keys

    /**
     * Der Angriff auf die Idempotenz: `markEventApplied()` schreibt den Key,
     * und der Merge liegt zwei Zeilen spaeter. Ohne Filter setzt ein Payload
     * `__appliedEventIds` auf leer zurueck — und derselbe Idempotenz-Key wirkt
     * ein zweites Mal.
     */
    public function testPayloadCannotResetAppliedEventIds(): void
    {
        $this->addCounterFlow();
        $engine = $this->engine();
        $instance = $engine->start('counter-flow', ['count' => 0]);

        $engine->handleEvent($instance->id, 'inc', ['__appliedEventIds' => []], 'key-1');
        self::assertSame(1, $instance->context['count']);

        // Derselbe Key ein zweites Mal: muss ein No-op bleiben.
        $engine->handleEvent($instance->id, 'inc', ['__appliedEventIds' => []], 'key-1');

        self::assertSame(1, $instance->context['count'], 'Die Idempotenz wurde ausgehebelt.');
        self::assertSame(['key-1'], $instance->context['__appliedEventIds']);
        self::assertContains('event_duplicate', $this->repo->historyKinds());
    }

    /**
     * Der Angriff auf fremde Instanzen. Er braucht zwei Schritte, weil
     * `notifyParentIfLinked()` beide Marker verlangt: das Opfer muss auf den
     * Angreifer warten (`__awaitWorkflow`), und der Angreifer muss auf das
     * Opfer zurueckverweisen (`__parent`). Beides sind Kontext-Schluessel —
     * ohne Filter also beides per Payload setzbar.
     */
    public function testPayloadCannotHijackAForeignInstance(): void
    {
        $this->addVictimFlow();
        $this->addAttackerFlow();
        $engine = $this->engine();

        $victim = $engine->start('victim-flow');
        $attacker = $engine->start('attacker-flow');
        self::assertSame(WorkflowInstance::WAITING_EVENT, $victim->status);

        // Schritt 1: dem Opfer einreden, es warte auf den Angreifer. Das Event
        // passt auf keine Transition — ohne Filter wuerde der Kontext trotzdem
        // gespeichert.
        $engine->handleEvent($victim->id, 'ping', [
            WorkflowStarterInterface::AWAIT_WORKFLOW => $attacker->id,
        ]);

        // Schritt 2: die eigene Instanz auf das Opfer zurueckverweisen lassen
        // und beenden.
        $engine->handleEvent($attacker->id, 'finish', [
            WorkflowStarterInterface::PARENT_LINK => ['instanceId' => $victim->id],
        ]);

        self::assertSame(WorkflowInstance::COMPLETED, $attacker->status);
        self::assertArrayNotHasKey(WorkflowStarterInterface::PARENT_LINK, $attacker->context);
        self::assertArrayNotHasKey(WorkflowStarterInterface::AWAIT_WORKFLOW, $victim->context);

        // Das Opfer steht unveraendert und wurde nicht fortgesetzt.
        self::assertSame(WorkflowInstance::WAITING_EVENT, $victim->status);
        self::assertSame('wait', $victim->currentStep);
        self::assertNotContains('subworkflow_done', $this->repo->historyKinds());
    }

    public function testPayloadCannotInjectSubWorkflowDepth(): void
    {
        $this->addCounterFlow();
        $engine = $this->engine();
        $instance = $engine->start('counter-flow', ['count' => 0]);

        $engine->handleEvent($instance->id, 'inc', [WorkflowStarterInterface::SUB_DEPTH => 99]);

        self::assertArrayNotHasKey(WorkflowStarterInterface::SUB_DEPTH, $instance->context);
        // Die Instanz laeuft regulaer weiter, der Filter blockiert nichts.
        self::assertSame(1, $instance->context['count']);
    }

    /**
     * Ein Payload kann Personendaten tragen. Ins Protokoll gehoeren deshalb
     * ausschliesslich die Schluesselnamen.
     */
    public function testRejectedKeysAreLoggedWithoutValues(): void
    {
        $this->addCounterFlow();
        $engine = $this->engine();
        $instance = $engine->start('counter-flow', ['count' => 0]);

        $engine->handleEvent($instance->id, 'inc', ['__parent' => 'geheim-sentinel']);

        $rejected = $this->historyOfKind('event_payload_rejected');
        self::assertCount(1, $rejected);
        self::assertSame(['__parent'], $rejected[0]['detail']['dropped']);
        self::assertStringNotContainsString(
            'geheim-sentinel',
            (string) json_encode($rejected[0]['detail']),
        );
    }

    /**
     * Der `event`-Eintrag nennt nur die angewandten Schluesselnamen: die
     * verworfenen tauchen nicht auf, und Werte schreibt er seit 1.16.0 keine mehr.
     */
    public function testEventHistoryLogsOnlyTheAppliedPayloadKeys(): void
    {
        $this->addCounterFlow();
        $engine = $this->engine();
        $instance = $engine->start('counter-flow', ['count' => 0]);

        $engine->handleEvent($instance->id, 'inc', ['note' => 'ok', '__parent' => 'geheim-sentinel']);

        $events = $this->historyOfKind('event');
        self::assertCount(1, $events);
        self::assertSame(['note'], $events[0]['detail']['payloadKeys']);
        self::assertArrayNotHasKey('payload', $events[0]['detail']);
        self::assertStringNotContainsString(
            'geheim-sentinel',
            (string) json_encode($events[0]['detail']),
        );
    }

    // ------------------------------------------------- Ebene B: Feld-Whitelist

    public function testAllowKeepsUndeclaredFields(): void
    {
        $this->addProfileFlow();
        $engine = $this->engine();
        $instance = $engine->start('profile-flow');

        $engine->handleEvent($instance->id, 'submit', ['phone' => '079', 'email' => 'fremd@example.test']);

        // Pinnt den rueckwaertskompatiblen Default: ohne Schalter aendert sich nichts.
        self::assertSame('fremd@example.test', $instance->context['email']);
    }

    public function testEnforceKeepsOnlyDeclaredFields(): void
    {
        $this->addProfileFlow();
        $engine = $this->engine(EventPayloadPolicy::Enforce);
        $instance = $engine->start('profile-flow');

        $engine->handleEvent($instance->id, 'submit', ['phone' => '079', 'email' => 'fremd@example.test']);

        self::assertSame('079', $instance->context['phone']);
        self::assertArrayNotHasKey('email', $instance->context);
    }

    /**
     * Fail closed. Ein reiner Button-Schritt (ui ohne fields, siehe
     * examples/order-check.json) nimmt nichts an — das Event wirkt trotzdem.
     */
    public function testEnforceRejectsEverythingWhenStepDeclaresNoFields(): void
    {
        $this->addButtonFlow();
        $engine = $this->engine(EventPayloadPolicy::Enforce);
        $instance = $engine->start('button-flow');

        $engine->handleEvent($instance->id, 'confirm', ['email' => 'fremd@example.test']);

        self::assertArrayNotHasKey('email', $instance->context);
        self::assertSame('done', $instance->currentStep, 'Die Transition muss trotzdem feuern.');
    }

    /** Ebene A schlaegt Ebene B: eine Definition darf den Namensraum nicht oeffnen. */
    public function testEnforceCannotBeWidenedByDeclaringAnInternalFieldName(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'sneaky-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => [
                    'type' => 'interactive',
                    'ui' => ['fields' => [['name' => '__parent'], ['name' => 'ok']]],
                    'transitions' => [['event' => 'submit', 'to' => 'done']],
                ],
                'done' => ['type' => 'automatic'],
            ],
        ]));
        $engine = $this->engine(EventPayloadPolicy::Enforce);
        $instance = $engine->start('sneaky-flow');

        $engine->handleEvent($instance->id, 'submit', ['__parent' => ['instanceId' => 'x'], 'ok' => true]);

        self::assertArrayNotHasKey('__parent', $instance->context);
        self::assertTrue($instance->context['ok']);
    }

    public function testReportKeepsPayloadButRecordsWhatEnforceWouldDrop(): void
    {
        $this->addProfileFlow();
        $engine = $this->engine(EventPayloadPolicy::Report);
        $instance = $engine->start('profile-flow');

        $engine->handleEvent($instance->id, 'submit', ['phone' => '079', 'email' => 'fremd@example.test']);

        // Report aendert nichts am Verhalten ...
        self::assertSame('fremd@example.test', $instance->context['email']);

        // ... schreibt aber auf, was Enforce verwerfen wuerde.
        $rejected = $this->historyOfKind('event_payload_rejected');
        self::assertCount(1, $rejected);
        self::assertSame([], $rejected[0]['detail']['dropped']);
        self::assertSame(['email'], $rejected[0]['detail']['wouldDrop']);
    }

    /**
     * `ui` ist ein rohes, ungetyptes Array — der DefinitionValidator fasst es
     * nicht an. Alles Unbrauchbare zaehlt als "keine Felder deklariert".
     *
     * @param array<string,mixed> $ui
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedUiProvider')]
    public function testMalformedUiFieldsAreTreatedAsNoFields(array $ui): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'odd-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => [
                    'type' => 'interactive',
                    'ui' => $ui,
                    'transitions' => [['event' => 'submit', 'to' => 'done']],
                ],
                'done' => ['type' => 'automatic'],
            ],
        ]));
        $engine = $this->engine(EventPayloadPolicy::Enforce);
        $instance = $engine->start('odd-flow');

        $engine->handleEvent($instance->id, 'submit', ['email' => 'fremd@example.test']);

        self::assertArrayNotHasKey('email', $instance->context);
        self::assertSame('done', $instance->currentStep);
    }

    /** @return array<string, array{array<string,mixed>}> */
    public static function malformedUiProvider(): array
    {
        return [
            'ohne ui.fields' => [['title' => 'Nur ein Knopf']],
            'fields als String' => [['fields' => 'phone,email']],
            'fields als leere Liste' => [['fields' => []]],
            'Eintraege als Strings' => [['fields' => ['phone', 'email']]],
            'Eintraege ohne name' => [['fields' => [['label' => 'Telefon']]]],
            'name nicht string' => [['fields' => [['name' => 42]]]],
        ];
    }

    // ---------------------------------------------------------------- Helfer

    /** @return list<array{instanceId:string,kind:string,step:?string,detail:array<string,mixed>}> */
    private function historyOfKind(string $kind): array
    {
        return array_values(array_filter(
            $this->repo->history(),
            static fn (array $h): bool => $h['kind'] === $kind,
        ));
    }

    private function addCounterFlow(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'counter-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => ['type' => 'interactive', 'transitions' => [['event' => 'inc', 'to' => 'bump']]],
                'bump' => ['type' => 'automatic', 'action' => 'bump', 'transitions' => [['to' => 'wait']]],
            ],
        ]));
        $this->actions->register('bump', $this->bumpAction());
    }

    /** Wartet auf ein Event, das es nicht gibt — bleibt also stehen. */
    private function addVictimFlow(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'victim-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => ['type' => 'interactive', 'transitions' => [['event' => 'nie', 'to' => 'done']]],
                'done' => ['type' => 'automatic'],
            ],
        ]));
    }

    private function addAttackerFlow(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'attacker-flow',
            'startStep' => 'go',
            'steps' => [
                'go' => ['type' => 'interactive', 'transitions' => [['event' => 'finish', 'to' => 'done']]],
                'done' => ['type' => 'automatic'],
            ],
        ]));
    }

    private function addProfileFlow(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'profile-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => [
                    'type' => 'interactive',
                    'ui' => ['fields' => [['name' => 'phone', 'label' => 'Telefon']]],
                    'transitions' => [['event' => 'submit', 'to' => 'done']],
                ],
                'done' => ['type' => 'automatic'],
            ],
        ]));
    }

    private function addButtonFlow(): void
    {
        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'button-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => [
                    'type' => 'interactive',
                    'ui' => ['title' => 'Zahlung ausstehend', 'events' => ['confirm']],
                    'transitions' => [['event' => 'confirm', 'to' => 'done']],
                ],
                'done' => ['type' => 'automatic'],
            ],
        ]));
    }

    private function bumpAction(): ActionInterface
    {
        return new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                $current = $instance->context['count'] ?? 0;
                $value = is_numeric($current) ? (int) $current : 0;

                return ['count' => $value + 1];
            }
        };
    }
}
