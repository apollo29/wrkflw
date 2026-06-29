<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/**
 * Ein laufender Durchlauf einer WorkflowDefinition.
 * Haelt den veraenderlichen Zustand: aktueller Step, Kontext, Status.
 */
final class WorkflowInstance
{
    public const RUNNING = 'running';
    public const WAITING_EVENT = 'waiting_event';   // wartet auf Frontend/Trigger
    public const WAITING_TIMER = 'waiting_timer';    // wartet auf Zeitpunkt (Cron)
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /**
     * @param array<string,mixed> $context frei erweiterbarer Variablen-Kontext
     */
    public function __construct(
        public string $id,
        public string $definitionId,
        public int $definitionVersion,
        public string $currentStep,
        public string $status,
        public array $context = [],
        public ?\DateTimeImmutable $wakeAt = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $lastError = null,
    ) {
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED], true);
    }

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * Merge eines Event-Payloads in den Kontext.
     *
     * @param array<string,mixed> $data
     */
    public function mergeContext(array $data): void
    {
        $this->context = array_replace($this->context, $data);
    }
}
