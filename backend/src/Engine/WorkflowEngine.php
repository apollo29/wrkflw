<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Contracts\WorkflowStarterInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\Transition;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\InvalidStateException;
use WorkflowEngine\Exception\MissingInputException;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\ContextKeys;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Die Engine. Operationen:
 *
 * - `start()`       neuen Durchlauf anlegen und bis zum ersten Halt laufen lassen
 * - `advance()`     automatische und fällige Schritte abarbeiten (auch vom Cron-Lauf)
 * - `handleEvent()` ein Ereignis (Knopfdruck) auf einen wartenden Durchlauf anwenden
 * - `goBack()`      «Ein Schritt zurück» (F-56)
 * - `cancel()`      Durchlauf von Hand abbrechen (offener Punkt 4)
 *
 * Ein Halt ist ein interaktiver Schritt, ein Warteschritt, das Warten auf einen
 * Kind-Ablauf oder ein Endzustand.
 */
final class WorkflowEngine implements WorkflowStarterInterface
{
    private readonly TransitionSelector $transitions;

    private readonly EventPayloadFilter $payloadFilter;

    private readonly RetryPolicy $retry;

    private readonly IdempotencyGuard $idempotency;

    private readonly WakeTimeCalculator $wakeTimes;

    private readonly BackTargetFinder $backTargets;

    public function __construct(
        private readonly WorkflowRepositoryInterface $repo,
        private readonly ActionRegistry $actions,
        ExpressionEvaluatorInterface $expressions,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly EngineOptions $options = new EngineOptions(),
    ) {
        $this->transitions = new TransitionSelector($expressions, $clock);
        $this->payloadFilter = new EventPayloadFilter($options->payloadPolicy);
        $this->retry = new RetryPolicy($options->maxAttempts, $options->baseRetryDelaySeconds);
        $this->idempotency = new IdempotencyGuard();
        $this->wakeTimes = new WakeTimeCalculator($expressions, $clock);
        $this->backTargets = new BackTargetFinder($repo);
    }

    /**
     * Startet einen Ablauf in der neuesten Version (F-12). Fehlen Pflichtwerte, entsteht
     * kein Durchlauf (F-21).
     *
     * @param array<string, mixed> $context
     */
    public function start(
        string $definitionId,
        array $context = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): WorkflowInstance {
        $def = $this->repo->findDefinition($definitionId);

        $missing = $def->missingInputs($context);
        if ($missing !== []) {
            throw new MissingInputException($def->id, $missing);
        }

        $instance = new WorkflowInstance(
            id: self::uuid(),
            definitionId: $def->id,
            definitionVersion: $def->version,
            currentStep: $def->startStep,
            status: InstanceStatus::Running,
            context: $context,
            subjectType: $subjectType,
            subjectId: $subjectId,
        );

        $this->repo->saveInstance($instance);
        $this->repo->logHistory($instance->id, HistoryKind::Start, $def->startStep, [
            'contextKeys' => array_keys(ContextKeys::stripInternal($context)),
        ]);

        $this->advance($instance, $def);

        return $instance;
    }

    /**
     * Arbeitet automatische und fällige Warteschritte ab, bis ein Halt erreicht ist.
     * Ein Durchlauf, dessen Weckzeit noch nicht erreicht ist, bleibt unverändert.
     */
    public function advance(WorkflowInstance $instance, ?WorkflowDefinition $def = null): void
    {
        if ($instance->isFinished() || $this->notYetDue($instance)) {
            return;
        }
        $def ??= $this->definitionOf($instance);

        for ($steps = 1; !$instance->isFinished(); $steps++) {
            if ($steps > $this->options->stepLimit) {
                $this->fail($instance, "Schritt-Limit von {$this->options->stepLimit} automatischen Schritten erreicht (mögliche Endlosschleife).");

                return;
            }

            $step = $def->step($instance->currentStep);

            if ($step->isInteractive()) {
                $this->halt($instance, InstanceStatus::WaitingEvent, null, HistoryKind::WaitEvent, $step->name);

                return;
            }

            if ($step->isTimer() && $instance->wakeAt === null) {
                try {
                    $wakeAt = $this->wakeTimes->wakeAt($step, $instance);
                } catch (\Throwable $e) {
                    $this->fail($instance, $e->getMessage());

                    return;
                }
                $this->halt($instance, InstanceStatus::WaitingTimer, $wakeAt, HistoryKind::WaitTimer, $step->name, [
                    'wakeAt' => $wakeAt->format(\DATE_ATOM),
                ]);

                return;
            }

            if ($step->isAutomatic() && $step->action !== null && !$this->runAction($instance, $step)) {
                return;
            }

            $next = $this->transitions->select($step, $instance, null);
            if ($next === null) {
                $this->finishStep($instance, $step);

                return;
            }

            $this->moveTo($instance, $step, $next);
        }
    }

    /**
     * Wendet ein Ereignis auf einen Durchlauf an, der an einem interaktiven Schritt wartet.
     * Die Eingaben werden gefiltert (ADR 6) und in den Kontext übernommen.
     *
     * @param array<array-key, mixed> $payload
     */
    public function handleEvent(string $instanceId, string $event, array $payload = [], ?string $eventId = null): WorkflowInstance
    {
        $instance = $this->load($instanceId);

        // Zuerst: ein bereits angewendetes Ereignis ist ein No-op, auch wenn der Durchlauf
        // inzwischen beendet ist (der zweite Klick eines Doppelklicks kommt oft später an).
        if ($eventId !== null && $this->idempotency->isApplied($instance, $eventId)) {
            $this->repo->logHistory($instance->id, HistoryKind::EventDuplicate, $instance->currentStep, [
                'event' => $event,
                'eventId' => $eventId,
            ]);

            return $instance;
        }

        if ($instance->isFinished()) {
            throw new InvalidStateException('Der Durchlauf ist bereits beendet.');
        }

        $def = $this->definitionOf($instance);
        $step = $def->step($instance->currentStep);
        if (!$step->isInteractive() || $instance->status !== InstanceStatus::WaitingEvent || $instance->awaitedChildId() !== null) {
            throw new InvalidStateException("Der Durchlauf wartet bei Schritt '{$step->name}' auf kein Ereignis.");
        }

        $filtered = $this->payloadFilter->filter($step, $payload);
        if ($eventId !== null) {
            $this->idempotency->markApplied($instance, $eventId);
        }
        if ($filtered->dropped !== [] || $filtered->wouldDrop !== []) {
            $this->repo->logHistory($instance->id, HistoryKind::EventPayloadRejected, $step->name, [
                'event' => $event,
                'dropped' => $filtered->dropped,
                'wouldDrop' => $filtered->wouldDrop,
            ]);
        }

        $instance->mergeContext($filtered->payload);
        $this->repo->logHistory($instance->id, HistoryKind::Event, $step->name, [
            'event' => $event,
            'payloadKeys' => array_keys($filtered->payload),
        ]);

        $next = $this->transitions->select($step, $instance, $event);
        if ($next === null) {
            $this->repo->saveInstance($instance);

            return $instance;
        }

        $this->moveTo($instance, $step, $next);
        $this->advance($instance, $def);

        return $instance;
    }

    /**
     * Gibt es von hier aus ein Zurück? Der Schritt muss es erlauben, und es muss ein Ziel
     * geben, im eigenen Ablauf oder im wartenden Eltern-Ablauf. Der Knopf erscheint nur,
     * wenn dies zutrifft (F-56).
     */
    public function canGoBack(WorkflowInstance $instance): bool
    {
        if ($instance->isFinished()) {
            return false;
        }
        try {
            $def = $this->definitionOf($instance);
            if (!$def->step($instance->currentStep)->allowsBack()) {
                return false;
            }
        } catch (NotFoundException) {
            return false;
        }

        if ($this->backTargets->find($instance, $def) !== null) {
            return true;
        }

        $parent = $this->parentOf($instance);

        return $parent !== null && $this->backTargets->find($parent, $this->definitionOf($parent)) !== null;
    }

    /**
     * Führt «Ein Schritt zurück» aus und gibt den Durchlauf zurück, der danach wartet.
     * Das kann der Eltern-Ablauf sein, wenn dieser Kind-Ablauf kein Ziel mehr hat: das
     * Kind wird dann abgebrochen, ohne den Eltern-Ablauf über sein Ende zu wecken.
     */
    public function goBack(string $instanceId): WorkflowInstance
    {
        $instance = $this->load($instanceId);
        if (!$this->canGoBack($instance)) {
            throw new InvalidStateException("Von Schritt '{$instance->currentStep}' aus gibt es kein Zurück.");
        }

        return $this->stepBack($instance);
    }

    /**
     * Bricht einen Durchlauf ab. Ein erwartetes Kind wird mit abgebrochen; ein wartender
     * Eltern-Ablauf läuft mit dem Endzustand «abgebrochen» weiter.
     */
    public function cancel(string $instanceId): WorkflowInstance
    {
        $instance = $this->load($instanceId);
        if ($instance->isFinished()) {
            throw new InvalidStateException('Der Durchlauf ist bereits beendet.');
        }

        $this->cancelInstance($instance, 'manual');
        $this->notifyParent($instance);

        return $instance;
    }

    /**
     * Führt die Aktion aus. Gibt false zurück, wenn der Durchlauf danach anhält
     * (Wiederholung geplant, Fehlschlag oder Warten auf ein Kind).
     */
    private function runAction(WorkflowInstance $instance, Step $step): bool
    {
        $action = (string) $step->action;
        try {
            $result = $this->actions->get($action)->execute($instance, $step);
        } catch (\Throwable $e) {
            $this->handleActionFailure($instance, $step, $e);

            return false;
        }

        $instance->mergeContext($result->context);
        $instance->lastError = null;
        $this->repo->logHistory($instance->id, HistoryKind::Action, $step->name, [
            'action' => $action,
            'resultKeys' => array_keys($result->context),
        ]);

        if ($result->awaitChildId !== null) {
            $instance->context[ContextKeys::AWAIT_WORKFLOW] = $result->awaitChildId;
            $this->halt($instance, InstanceStatus::WaitingEvent, null, HistoryKind::WaitSubWorkflow, $step->name, [
                'childId' => $result->awaitChildId,
            ]);

            return false;
        }

        return true;
    }

    private function handleActionFailure(WorkflowInstance $instance, Step $step, \Throwable $e): void
    {
        $instance->attempts++;
        $instance->lastError = $e->getMessage();

        if ($e instanceof PermanentFailureInterface) {
            $this->fail($instance, "Aktion '{$step->action}' fehlgeschlagen: {$e->getMessage()}");

            return;
        }

        $delay = $this->retry->delayAfterAttempt($instance->attempts);
        if ($delay === null) {
            $this->fail($instance, "Aktion '{$step->action}' nach {$instance->attempts} Versuch(en) fehlgeschlagen: {$e->getMessage()}");

            return;
        }

        $wakeAt = $this->clock->now()->modify("+{$delay} seconds");
        $this->halt($instance, InstanceStatus::WaitingTimer, $wakeAt, HistoryKind::Retry, $step->name, [
            'attempt' => $instance->attempts,
            'maxAttempts' => $this->retry->maxAttempts,
            'nextAttemptAt' => $wakeAt->format(\DATE_ATOM),
        ]);
    }

    /** Kein passender Übergang: entweder Ende oder eine Sackgasse. */
    private function finishStep(WorkflowInstance $instance, Step $step): void
    {
        if (TransitionSelector::isDeadEnd($step)) {
            $this->fail($instance, "Schritt '{$step->name}' hat keinen Ausgang ohne Ereignis: jeder Übergang verlangt einen Knopfdruck, den ein nicht-interaktiver Schritt nie bekommt.");

            return;
        }

        $instance->status = InstanceStatus::Completed;
        $instance->wakeAt = null;
        $this->repo->saveInstance($instance);
        $this->repo->logHistory($instance->id, HistoryKind::Complete, $step->name);
        $this->notifyParent($instance);
    }

    private function moveTo(WorkflowInstance $instance, Step $from, Transition $transition): void
    {
        $this->repo->logHistory($instance->id, HistoryKind::Transition, $from->name, ['to' => $transition->to]);
        $instance->currentStep = $transition->to;
        $instance->status = InstanceStatus::Running;
        $instance->wakeAt = null;
        $instance->attempts = 0;
        $this->repo->saveInstance($instance);
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function halt(
        WorkflowInstance $instance,
        InstanceStatus $status,
        ?\DateTimeImmutable $wakeAt,
        HistoryKind $kind,
        string $step,
        array $detail = [],
    ): void {
        $instance->status = $status;
        $instance->wakeAt = $wakeAt;
        $this->repo->saveInstance($instance);
        $this->repo->logHistory($instance->id, $kind, $step, $detail);
    }

    private function fail(WorkflowInstance $instance, string $message): void
    {
        $instance->status = InstanceStatus::Failed;
        $instance->wakeAt = null;
        $instance->lastError = $message;
        $this->repo->saveInstance($instance);
        $this->repo->logHistory($instance->id, HistoryKind::Error, $instance->currentStep, ['message' => $message]);
        $this->notifyParent($instance);
    }

    private function cancelInstance(WorkflowInstance $instance, string $reason): void
    {
        $childId = $instance->awaitedChildId();
        if ($childId !== null) {
            $child = $this->repo->findInstance($childId);
            if ($child !== null && !$child->isFinished()) {
                $this->cancelInstance($child, 'parent_cancelled');
            }
            unset($instance->context[ContextKeys::AWAIT_WORKFLOW]);
        }

        $instance->status = InstanceStatus::Cancelled;
        $instance->wakeAt = null;
        $this->repo->saveInstance($instance);
        $this->repo->logHistory($instance->id, HistoryKind::Cancelled, $instance->currentStep, ['reason' => $reason]);
    }

    private function stepBack(WorkflowInstance $instance): WorkflowInstance
    {
        $def = $this->definitionOf($instance);
        $target = $this->backTargets->find($instance, $def);
        if ($target !== null) {
            $from = $instance->currentStep;
            $instance->currentStep = $target;
            $instance->status = InstanceStatus::WaitingEvent;
            $instance->wakeAt = null;
            $instance->attempts = 0;
            $this->repo->saveInstance($instance);
            $this->repo->logHistory($instance->id, HistoryKind::Back, $from, ['to' => $target]);

            return $instance;
        }

        $parent = $this->parentOf($instance)
            ?? throw new InvalidStateException("Von Schritt '{$instance->currentStep}' aus gibt es kein Zurück.");

        // Zuerst lösen, dann abbrechen: das Ende dieses Kindes darf den Eltern-Ablauf,
        // der zurückgeht, nicht wecken.
        unset($parent->context[ContextKeys::AWAIT_WORKFLOW]);
        $this->repo->saveInstance($parent);
        $this->cancelInstance($instance, 'back');

        return $this->stepBack($parent);
    }

    /**
     * Weckt einen Eltern-Ablauf, der genau auf dieses (beendete) Kind wartet. Er erhält
     * Ergebnis und Endzustand und geht über seinen Übergang ohne Ereignis weiter, ohne den
     * Schritt erneut auszuführen.
     */
    private function notifyParent(WorkflowInstance $child): void
    {
        $parent = $this->parentOf($child);
        if ($parent === null || $parent->awaitedChildId() !== $child->id) {
            return;
        }

        $def = $this->definitionOf($parent);
        $step = $def->step($parent->currentStep);

        unset($parent->context[ContextKeys::AWAIT_WORKFLOW]);
        $parent->mergeContext(['subWorkflow' => [
            'id' => $child->id,
            'definitionId' => $child->definitionId,
            'status' => $child->status->value,
            'context' => $child->publicContext(),
        ]]);
        $parent->status = InstanceStatus::Running;
        $this->repo->saveInstance($parent);
        $this->repo->logHistory($parent->id, HistoryKind::SubWorkflowDone, $step->name, [
            'childId' => $child->id,
            'status' => $child->status->value,
        ]);

        $next = $this->transitions->select($step, $parent, null);
        if ($next === null) {
            $this->finishStep($parent, $step);

            return;
        }

        $this->moveTo($parent, $step, $next);
        $this->advance($parent, $def);
    }

    private function notYetDue(WorkflowInstance $instance): bool
    {
        return $instance->status === InstanceStatus::WaitingTimer
            && $instance->wakeAt !== null
            && $instance->wakeAt > $this->clock->now();
    }

    private function parentOf(WorkflowInstance $instance): ?WorkflowInstance
    {
        $parentId = $instance->parentId();
        $parent = $parentId === null ? null : $this->repo->findInstance($parentId);

        return $parent !== null && !$parent->isFinished() ? $parent : null;
    }

    private function definitionOf(WorkflowInstance $instance): WorkflowDefinition
    {
        return $this->repo->findDefinition($instance->definitionId, $instance->definitionVersion);
    }

    private function load(string $instanceId): WorkflowInstance
    {
        return $this->repo->findInstance($instanceId) ?? throw NotFoundException::instance($instanceId);
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
