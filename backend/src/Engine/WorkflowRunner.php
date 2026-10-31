<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WorkflowEngine\Contracts\InstanceQueueInterface;
use WorkflowEngine\Contracts\TriggerInterface;
use WorkflowEngine\Contracts\TriggerLockInterface;
use WorkflowEngine\Contracts\TriggerSourceInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Trigger\RegisteredTrigger;
use WorkflowEngine\Trigger\TriggerStart;

/**
 * Der Hintergrundlauf, vom Cron aufgerufen (ADR 2, Q-07):
 *
 * 1. fällige Durchläufe belegen und weiterschalten (Timer, Wiederholungen, hängende Läufe
 *    nach Ablauf der Lease),
 * 2. Trigger abfragen und Durchläufe starten, mit Sperrfrist je Datensatz.
 *
 * Ein Fehler betrifft nur seinen Durchlauf oder Start; der Lauf geht weiter.
 */
final class WorkflowRunner
{
    /** @var list<RegisteredTrigger> */
    private array $triggers = [];

    /** @var list<TriggerSourceInterface> */
    private array $sources = [];

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly WorkflowRepositoryInterface&InstanceQueueInterface $repo,
        private readonly TriggerLockInterface $lock,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $batchSize = 50,
        private readonly int $leaseSeconds = 300,
    ) {
    }

    /**
     * @param string   $key         Name des Triggers, Teil des Sperrschlüssels
     * @param int|null $lockSeconds Sperrfrist je Datensatz; null = jeder Lauf darf starten
     */
    public function addTrigger(TriggerInterface $trigger, string $key, ?int $lockSeconds = null): void
    {
        $this->triggers[] = new RegisteredTrigger($trigger, $key, $lockSeconds);
    }

    /** Eine Quelle, die vor jedem Durchgang die fälligen Trigger liefert (Admin-Trigger). */
    public function addTriggerSource(TriggerSourceInterface $source): void
    {
        $this->sources[] = $source;
    }

    public function tick(): RunnerStats
    {
        $woken = 0;
        $started = 0;
        $locked = 0;
        $errors = 0;

        foreach ($this->repo->claimDue($this->clock->now(), $this->batchSize, $this->leaseSeconds) as $instance) {
            try {
                $this->engine->advance($instance);
                $woken++;
            } catch (\Throwable $e) {
                $errors++;
                $this->repo->logHistory($instance->id, HistoryKind::Error, $instance->currentStep, ['message' => $e->getMessage()]);
                $this->logger->error('workflow.advance_failed', ['instanceId' => $instance->id, 'step' => $instance->currentStep, 'error' => $e->getMessage()]);
            }
        }

        $triggers = $this->triggers;
        foreach ($this->sources as $source) {
            try {
                array_push($triggers, ...$source->triggers($this->clock->now()));
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('workflow.trigger_source_failed', ['error' => $e->getMessage()]);
            }
        }

        foreach ($triggers as $registered) {
            $key = $registered->key;
            $lockSeconds = $registered->lockSeconds;
            try {
                $starts = $registered->trigger->poll();
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('workflow.trigger_poll_failed', ['trigger' => $key, 'error' => $e->getMessage()]);
                continue;
            }

            foreach ($starts as $start) {
                $subject = self::subjectKey($start);
                if ($lockSeconds !== null && $subject !== null) {
                    $now = $this->clock->now();
                    if (!$this->lock->acquire($key, $subject, $now, $now->modify("+{$lockSeconds} seconds"))) {
                        $locked++;
                        continue;
                    }
                }

                try {
                    $this->engine->start($start->definitionId, $start->context, $start->subjectType, $start->subjectId);
                    $started++;
                } catch (\Throwable $e) {
                    $errors++;
                    if ($lockSeconds !== null && $subject !== null) {
                        $this->lock->release($key, $subject);
                    }
                    $this->logger->error('workflow.trigger_start_failed', [
                        'trigger' => $key,
                        'definition' => $start->definitionId,
                        'subject' => $subject,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $stats = new RunnerStats($woken, $started, $locked, $errors);
        $this->logger->info('workflow.tick', $stats->toArray());

        return $stats;
    }

    private static function subjectKey(TriggerStart $start): ?string
    {
        if ($start->subjectId === null || $start->subjectId === '') {
            return null;
        }

        return ($start->subjectType ?? '') . ':' . $start->subjectId;
    }
}
