<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use WorkflowEngine\Contracts\TriggerInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;

/**
 * Cron-Einstieg. Per Cron (z. B. jede Minute) aufrufen.
 *
 * Aufgaben:
 *   1) Faellige Timer-Instanzen nebenlaeufigkeitssicher abholen und weiterlaufen lassen.
 *   2) Registrierte datengetriebene Trigger pruefen und ggf. neue Instanzen starten.
 *
 * Bewusst ohne Message-Queue: DB-Polling mit Locking, keine zusaetzliche Infra.
 * Mehrere parallele Runner sind sicher: das Abholen ueber claimDueInstances() sperrt
 * die Zeilen und ueberspringt bereits abgeholte Instanzen.
 */
final class WorkflowRunner
{
    /** @var list<TriggerInterface> */
    private array $triggers = [];

    private readonly LoggerInterface $logger;

    /**
     * @param int $leaseSeconds Nach dieser Spanne gelten RUNNING-Instanzen als haengen
     *                          geblieben und werden erneut abgeholt (0 = deaktiviert).
     */
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly WorkflowRepositoryInterface $repo,
        ?LoggerInterface $logger = null,
        private readonly int $leaseSeconds = 300,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function addTrigger(TriggerInterface $trigger): void
    {
        $this->triggers[] = $trigger;
    }

    /**
     * Ein Tick. Gibt eine kurze Statistik zurueck (fuer Logging/Monitoring).
     *
     * @return array{woken:int,started:int,errors:int}
     */
    public function tick(int $batchSize = 50): array
    {
        $now = new \DateTimeImmutable();
        $woken = 0;
        $started = 0;
        $errors = 0;

        // 1) Faellige Timer- und haengende RUNNING-Instanzen (atomar abgeholt).
        foreach ($this->repo->claimDueInstances($now, $batchSize, $this->leaseSeconds) as $instance) {
            try {
                $this->engine->advance($instance);
                $woken++;
            } catch (\Throwable $e) {
                $errors++;
                $this->repo->logHistory($instance->id, 'error', $instance->currentStep, [
                    'message' => $e->getMessage(),
                ]);
                $this->logger->error('workflow.advance_failed', [
                    'instanceId' => $instance->id,
                    'step' => $instance->currentStep,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2) Datengetriebene Start-Trigger.
        foreach ($this->triggers as $trigger) {
            foreach ($trigger->poll() as $job) {
                try {
                    $this->engine->start(
                        $job['definition'],
                        $job['context'] ?? [],
                        $job['subjectType'] ?? null,
                        $job['subjectId'] ?? null,
                    );
                    $started++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->error('workflow.trigger_failed', [
                        'definition' => $job['definition'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $stats = ['woken' => $woken, 'started' => $started, 'errors' => $errors];
        $this->logger->info('workflow.tick', $stats);

        return $stats;
    }
}
