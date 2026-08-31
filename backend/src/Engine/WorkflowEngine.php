<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Contracts\WorkflowStarterInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\Transition;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\ContextKeys;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Die zentrale Engine. Kennt drei Operationen:
 *   start()       - neue Instanz erzeugen und bis zum ersten Halt laufen lassen
 *   advance()     - automatische Schritte abarbeiten, bis ein Halt erreicht ist
 *   handleEvent() - ein Frontend-/Trigger-Event auf eine wartende Instanz anwenden
 *
 * "Halt" bedeutet: interaktiver Schritt (wartet auf Event), Timer-Schritt
 * (wartet auf Zeitpunkt) oder Endzustand (completed/failed).
 */
final class WorkflowEngine implements WorkflowStarterInterface
{
    private const STEP_LIMIT = 1000;

    /** Reservierter Kontext-Schluessel fuer bereits angewendete Idempotenz-Keys. */
    private const APPLIED_EVENTS_KEY = '__appliedEventIds';

    /** Obergrenze gespeicherter Idempotenz-Keys pro Instanz (aelteste werden verworfen). */
    private const MAX_APPLIED_EVENTS = 50;

    /**
     * @param int $maxAttempts          maximale Ausfuehrungsversuche einer Action,
     *                                  bevor die Instanz auf 'failed' geht (>= 1)
     * @param int $baseRetryDelaySeconds Basis-Verzoegerung fuer den exponentiellen Backoff
     */
    public function __construct(
        private readonly WorkflowRepositoryInterface $repo,
        private readonly ActionRegistry $actions,
        private readonly ExpressionEvaluatorInterface $expr,
        private readonly int $maxAttempts = 3,
        private readonly int $baseRetryDelaySeconds = 60,
        /**
         * Wie streng Event-Payloads gefiltert werden (ADR 0006). Der Default ist
         * rueckwaertskompatibel; interne Schluessel werden davon unabhaengig
         * immer verworfen.
         */
        private readonly EventPayloadPolicy $eventPayloadPolicy = EventPayloadPolicy::Allow,
    ) {
    }

    /**
     * Startet einen Workflow. Ausloeser kann alles sein: API-Call, Cron,
     * ein Event aus der Host-App.
     *
     * @param array<string,mixed> $context Anfangs-Kontext (Eingangsdaten)
     */
    public function start(
        string $definitionId,
        array $context = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): WorkflowInstance {
        $def = $this->repo->findDefinition($definitionId);

        $instance = new WorkflowInstance(
            id: $this->uuid(),
            definitionId: $def->id,
            definitionVersion: $def->version,
            currentStep: $def->startStep,
            status: WorkflowInstance::RUNNING,
            context: $context,
            subjectType: $subjectType,
            subjectId: $subjectId,
        );

        $this->repo->saveInstance($instance);
        // Nur die Schluesselnamen: der Initial-Kontext traegt Personendaten
        // (Namen, Adressen, Bemerkungen), und die History ist ein dauerhaftes,
        // in der Oberflaeche sichtbares Protokoll.
        $this->repo->logHistory($instance->id, 'start', $def->startStep, [
            'contextKeys' => array_keys($context),
        ]);

        $this->advance($instance, $def);

        return $instance;
    }

    /**
     * PORT {@see WorkflowStarterInterface}: startet einen verknuepften Workflow.
     * Duenner Delegate auf start(); der Anfangs-Kontext (inkl. evtl. Eltern-Verweis)
     * wird vom Aufrufer (start_workflow-Action) vorbereitet.
     */
    public function startWorkflow(
        string $definitionId,
        array $context = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): WorkflowInstance {
        return $this->start($definitionId, $context, $subjectType, $subjectId);
    }

    /**
     * Arbeitet automatische und faellige Timer-Schritte ab, bis ein Halt erreicht ist.
     * Wird von start(), handleEvent() und dem Cron-Runner benutzt.
     */
    public function advance(WorkflowInstance $instance, ?WorkflowDefinition $def = null): void
    {
        $def ??= $this->repo->findDefinition($instance->definitionId, $instance->definitionVersion);

        $guard = 0;
        while (!$instance->isFinished()) {
            if (++$guard > self::STEP_LIMIT) {
                $this->fail($instance, 'Schritt-Limit erreicht (moegliche Endlosschleife).');
                break;
            }

            $step = $def->step($instance->currentStep);

            // 1) Interaktiver Schritt -> anhalten, auf Event warten.
            if ($step->isInteractive()) {
                $instance->status = WorkflowInstance::WAITING_EVENT;
                $instance->wakeAt = null;
                $this->repo->saveInstance($instance);
                $this->repo->logHistory($instance->id, 'wait_event', $step->name);

                return;
            }

            // 2) Timer-Schritt -> wake_at setzen, auf Cron warten (sofern noch nicht faellig).
            if ($step->isTimer() && !$this->timerElapsed($instance)) {
                $wakeAt = $this->computeWakeAt($step, $instance);
                $instance->status = WorkflowInstance::WAITING_TIMER;
                $instance->wakeAt = $wakeAt;
                $this->repo->saveInstance($instance);
                $this->repo->logHistory($instance->id, 'wait_timer', $step->name, [
                    'wakeAt' => $wakeAt->format(DATE_ATOM),
                ]);

                return;
            }

            // 3) Automatischer Schritt -> Aktion ausfuehren.
            if ($step->type === Step::AUTOMATIC && $step->action !== null) {
                try {
                    $result = $this->actions->get($step->action)->execute($instance, $step);
                    $instance->mergeContext($result);
                    $this->repo->logHistory($instance->id, 'action', $step->name, [
                        'action' => $step->action,
                        // Nur die Schluessel: SendEmailAction liefert
                        // `lastEmailTo`, CheckDataAction den gelesenen Feldwert.
                        'resultKeys' => array_keys($result),
                    ]);
                } catch (\Throwable $e) {
                    $this->handleActionFailure($instance, $step, $e);

                    return;
                }

                // Verknuepfter Workflow im Warten-Modus: haelt das Kind an, wartet auch
                // der Eltern-Schritt, bis das Kind fertig ist (weckt via notifyParent).
                if (array_key_exists(WorkflowStarterInterface::AWAIT_WORKFLOW, $result)) {
                    $childId = $result[WorkflowStarterInterface::AWAIT_WORKFLOW];
                    $instance->status = WorkflowInstance::WAITING_EVENT;
                    $instance->wakeAt = null;
                    $this->repo->saveInstance($instance);
                    $this->repo->logHistory($instance->id, 'wait_subworkflow', $step->name, [
                        'childId' => $childId,
                    ]);

                    return;
                }
            }

            // 4) Naechste Transition ohne Event-Bindung bestimmen.
            $next = $this->selectTransition($step, $instance, event: null);
            if ($next === null) {
                $instance->status = WorkflowInstance::COMPLETED;
                $instance->wakeAt = null;
                $this->repo->saveInstance($instance);
                $this->repo->logHistory($instance->id, 'complete', $step->name);
                $this->notifyParentIfLinked($instance);

                return;
            }

            $this->moveTo($instance, $step, $next);
        }
    }

    /**
     * Wendet ein Event auf eine wartende Instanz an (Button-Klick im Frontend
     * oder ein API-Trigger). Payload wird in den Kontext gemerged.
     *
     * @param array<string,mixed> $payload
     */
    public function handleEvent(
        string $instanceId,
        string $event,
        array $payload = [],
        ?string $eventId = null,
    ): WorkflowInstance {
        $instance = $this->repo->findInstance($instanceId)
            ?? throw new WorkflowException("Instanz '{$instanceId}' nicht gefunden.");

        if ($instance->isFinished()) {
            throw new WorkflowException('Workflow ist bereits beendet.');
        }

        // Idempotenz: ein bereits angewendetes Event (gleicher eventId) ist ein No-op.
        if ($eventId !== null && $this->isEventApplied($instance, $eventId)) {
            $this->repo->logHistory($instance->id, 'event_duplicate', $instance->currentStep, [
                'event' => $event,
                'eventId' => $eventId,
            ]);

            return $instance;
        }

        $def = $this->repo->findDefinition($instance->definitionId, $instance->definitionVersion);
        $step = $def->step($instance->currentStep);

        $clean = $this->sanitizeEventPayload($step, $payload);

        if ($eventId !== null) {
            $this->markEventApplied($instance, $eventId);
        }

        if ($clean['dropped'] !== [] || $clean['wouldDrop'] !== []) {
            // Nur Schluesselnamen, nie Werte: ein Payload kann Personendaten tragen.
            $this->repo->logHistory($instance->id, 'event_payload_rejected', $step->name, [
                'event' => $event,
                'dropped' => $clean['dropped'],
                'wouldDrop' => $clean['wouldDrop'],
            ]);
        }

        $instance->mergeContext($clean['payload']);
        $this->repo->logHistory($instance->id, 'event', $step->name, [
            'event' => $event,
            // Nur die Schluessel des GEFILTERTEN Payloads: welche Felder gesetzt
            // wurden, bleibt nachvollziehbar, die Werte wandern nicht ins
            // Protokoll.
            'payloadKeys' => array_keys($clean['payload']),
        ]);

        $next = $this->selectTransition($step, $instance, event: $event);
        if ($next === null) {
            // Event passt auf keine Transition -> Kontext gespeichert, sonst keine Bewegung.
            $this->repo->saveInstance($instance);

            return $instance;
        }

        $this->moveTo($instance, $step, $next);
        $this->advance($instance, $def);

        return $instance;
    }

    /**
     * Waehlt die erste Transition, deren Bedingung erfuellt ist.
     * Bei $event !== null muessen Transition->event und $event uebereinstimmen;
     * bei $event === null werden nur Transitionen ohne Event-Bindung betrachtet.
     */
    private function selectTransition(Step $step, WorkflowInstance $instance, ?string $event): ?Transition
    {
        $scope = ['context' => $instance->context, 'now' => time()];

        foreach ($step->transitions as $t) {
            if ($event === null && $t->event !== null) {
                continue;
            }
            if ($event !== null && $t->event !== $event) {
                continue;
            }
            if ($this->expr->evaluate($t->when, $scope)) {
                return $t;
            }
        }

        return null;
    }

    private function moveTo(WorkflowInstance $instance, Step $from, Transition $t): void
    {
        $this->repo->logHistory($instance->id, 'transition', $from->name, ['to' => $t->to]);
        $instance->currentStep = $t->to;
        $instance->status = WorkflowInstance::RUNNING;
        $instance->wakeAt = null;
        $instance->attempts = 0; // neuer Schritt -> Retry-Zaehler zuruecksetzen
        $this->repo->saveInstance($instance);
    }

    /**
     * Behandelt eine fehlgeschlagene Action: bis zur Obergrenze wird mit
     * exponentiellem Backoff als Timer neu geplant, danach geht die Instanz
     * auf 'failed'.
     */
    private function handleActionFailure(WorkflowInstance $instance, Step $step, \Throwable $e): void
    {
        $instance->attempts++;

        if ($instance->attempts < $this->maxAttempts) {
            $delay = $this->baseRetryDelaySeconds * (2 ** ($instance->attempts - 1));
            $wakeAt = (new \DateTimeImmutable())->modify("+{$delay} seconds");

            $instance->status = WorkflowInstance::WAITING_TIMER;
            $instance->wakeAt = $wakeAt;
            $instance->lastError = $e->getMessage();
            $this->repo->saveInstance($instance);
            $this->repo->logHistory($instance->id, 'retry', $step->name, [
                'attempt' => $instance->attempts,
                'maxAttempts' => $this->maxAttempts,
                'error' => $e->getMessage(),
                'nextAttemptAt' => $wakeAt->format(DATE_ATOM),
            ]);

            return;
        }

        $this->fail(
            $instance,
            "Aktion '{$step->action}' nach {$instance->attempts} Versuch(en) fehlgeschlagen: {$e->getMessage()}",
        );
    }

    private function fail(WorkflowInstance $instance, string $msg): void
    {
        $instance->status = WorkflowInstance::FAILED;
        $instance->lastError = $msg;
        $this->repo->saveInstance($instance);
        $this->repo->logHistory($instance->id, 'error', $instance->currentStep, ['message' => $msg]);
        $this->notifyParentIfLinked($instance);
    }

    /**
     * Ist die soeben beendete Instanz ein Kind-Workflow, dessen Eltern auf sie wartet,
     * wird der Eltern-Workflow fortgesetzt (verknuepfte Workflows, Warten-Modus).
     */
    private function notifyParentIfLinked(WorkflowInstance $child): void
    {
        $parentLink = $child->context[WorkflowStarterInterface::PARENT_LINK] ?? null;
        if (!is_array($parentLink)) {
            return;
        }
        $parentId = $parentLink['instanceId'] ?? null;
        if (!is_string($parentId)) {
            return;
        }

        $parent = $this->repo->findInstance($parentId);
        if ($parent === null || $parent->isFinished()) {
            return;
        }
        // Nur fortsetzen, wenn der Eltern-Workflow wirklich auf genau dieses Kind wartet.
        if (($parent->context[WorkflowStarterInterface::AWAIT_WORKFLOW] ?? null) !== $child->id) {
            return;
        }

        $this->resumeFromSubWorkflow($parent, $child);
    }

    /**
     * Setzt einen wartenden Eltern-Workflow fort, nachdem sein Kind fertig ist: schreibt
     * das Kind-Ergebnis in den Kontext und geht ueber die regulaere (event-lose)
     * Transition des aktuellen Schritts weiter.
     */
    private function resumeFromSubWorkflow(WorkflowInstance $parent, WorkflowInstance $child): void
    {
        $def = $this->repo->findDefinition($parent->definitionId, $parent->definitionVersion);
        $step = $def->step($parent->currentStep);

        unset($parent->context[WorkflowStarterInterface::AWAIT_WORKFLOW]);
        $parent->mergeContext([
            'subWorkflow' => [
                'id' => $child->id,
                'definitionId' => $child->definitionId,
                'status' => $child->status,
                'context' => $this->publicContext($child->context),
            ],
        ]);
        $parent->status = WorkflowInstance::RUNNING;
        $parent->wakeAt = null;
        $this->repo->saveInstance($parent);
        $this->repo->logHistory($parent->id, 'subworkflow_done', $step->name, [
            'childId' => $child->id,
            'status' => $child->status,
        ]);

        // Ueber die naechste event-lose Transition weiter (Schritt nicht erneut ausfuehren).
        $next = $this->selectTransition($step, $parent, event: null);
        if ($next === null) {
            $parent->status = WorkflowInstance::COMPLETED;
            $parent->wakeAt = null;
            $this->repo->saveInstance($parent);
            $this->repo->logHistory($parent->id, 'complete', $step->name);
            $this->notifyParentIfLinked($parent);

            return;
        }

        $this->moveTo($parent, $step, $next);
        $this->advance($parent, $def);
    }

    /**
     * Filtert einen Event-Payload an der Grenze zwischen Aussenwelt und
     * Instanz-Kontext (ADR 0006).
     *
     * Zwei Ebenen:
     *   A) Engine-interne Schluessel ("__") werden IMMER verworfen. Sie steuern
     *      Idempotenz und die Verknuepfung von Workflows; kaemen sie aus einem
     *      Payload, liessen sich damit die Idempotenz aushebeln und fremde
     *      Instanzen fortsetzen.
     *   B) Je nach Policy zusaetzlich die Whitelist aus `ui.fields`.
     *
     * Verworfen wird, nicht abgelehnt: ein zusaetzlicher Schluessel aus einem
     * aelteren Client soll den Schritt nicht blockieren.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{payload: array<string,mixed>, dropped: list<string>, wouldDrop: list<string>}
     */
    private function sanitizeEventPayload(Step $step, array $payload): array
    {
        $kept = [];
        $dropped = [];
        foreach ($payload as $key => $value) {
            $name = (string) $key;
            if (ContextKeys::isInternal($name)) {
                $dropped[] = $name;
                continue;
            }
            $kept[$name] = $value;
        }

        if ($this->eventPayloadPolicy === EventPayloadPolicy::Allow) {
            return ['payload' => $kept, 'dropped' => $dropped, 'wouldDrop' => []];
        }

        $allowed = array_fill_keys($this->declaredFieldNames($step), true);
        $undeclared = array_keys(array_diff_key($kept, $allowed));

        if ($this->eventPayloadPolicy === EventPayloadPolicy::Report) {
            return ['payload' => $kept, 'dropped' => $dropped, 'wouldDrop' => $undeclared];
        }

        return [
            'payload' => array_intersect_key($kept, $allowed),
            'dropped' => [...$dropped, ...$undeclared],
            'wouldDrop' => [],
        ];
    }

    /**
     * Die vom Schritt deklarierten Feldnamen.
     *
     * `ui` ist ein rohes, ungetyptes Array — der DefinitionValidator fasst es
     * nicht an. Alles Unbrauchbare zaehlt deshalb als "keine Felder
     * deklariert", und unter Enforce kommt dann nichts durch (fail closed).
     *
     * Intern benannte Felder werden verworfen: sonst risse eine Definition mit
     * `{"name": "__parent"}` die Ebene A wieder auf.
     *
     * @return list<string>
     */
    private function declaredFieldNames(Step $step): array
    {
        $fields = $step->ui['fields'] ?? null;
        if (!is_array($fields)) {
            return [];
        }

        $names = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = $field['name'] ?? null;
            if (!is_string($name) || $name === '' || ContextKeys::isInternal($name)) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * Entfernt engine-interne Schluessel aus einem Kontext.
     *
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    private function publicContext(array $context): array
    {
        return ContextKeys::stripInternal($context);
    }

    private function isEventApplied(WorkflowInstance $instance, string $eventId): bool
    {
        $applied = $instance->context[self::APPLIED_EVENTS_KEY] ?? [];

        return is_array($applied) && in_array($eventId, $applied, true);
    }

    private function markEventApplied(WorkflowInstance $instance, string $eventId): void
    {
        $applied = $instance->context[self::APPLIED_EVENTS_KEY] ?? [];
        if (!is_array($applied)) {
            $applied = [];
        }
        $applied[] = $eventId;
        // Wachstum begrenzen: nur die juengsten Keys behalten.
        if (count($applied) > self::MAX_APPLIED_EVENTS) {
            $applied = array_slice($applied, -self::MAX_APPLIED_EVENTS);
        }
        $instance->context[self::APPLIED_EVENTS_KEY] = array_values($applied);
    }

    private function timerElapsed(WorkflowInstance $instance): bool
    {
        if ($instance->wakeAt === null) {
            return false; // noch nie gesetzt -> erst warten
        }

        return $instance->wakeAt <= new \DateTimeImmutable();
    }

    private function computeWakeAt(Step $step, WorkflowInstance $instance): \DateTimeImmutable
    {
        if ($step->delaySeconds !== null) {
            return (new \DateTimeImmutable())->modify("+{$step->delaySeconds} seconds");
        }

        if ($step->untilExpr !== null) {
            $value = $this->expr->evaluateValue($step->untilExpr, [
                'context' => $instance->context,
                'now' => time(),
            ]);
            if (!is_numeric($value)) {
                throw new WorkflowException("Timer 'until' in Step '{$step->name}' lieferte keinen Zeitstempel.");
            }

            return (new \DateTimeImmutable())->setTimestamp((int) $value);
        }

        // Fallback: sofort faellig.
        return new \DateTimeImmutable();
    }

    private function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = \chr((\ord($d[6]) & 0x0f) | 0x40);
        $d[8] = \chr((\ord($d[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }
}
