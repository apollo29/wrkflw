<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\Transition;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\WorkflowException;
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
final class WorkflowEngine
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
        $this->repo->logHistory($instance->id, 'start', $def->startStep, ['context' => $context]);

        $this->advance($instance, $def);

        return $instance;
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
                        'result' => $result,
                    ]);
                } catch (\Throwable $e) {
                    $this->handleActionFailure($instance, $step, $e);

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

        if ($eventId !== null) {
            $this->markEventApplied($instance, $eventId);
        }
        $instance->mergeContext($payload);
        $this->repo->logHistory($instance->id, 'event', $step->name, [
            'event' => $event,
            'payload' => $payload,
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
