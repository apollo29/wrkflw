<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use WorkflowEngine\Instance\ContextKeys;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Merkt sich die zuletzt angewendeten Ereignis-IDs eines Durchlaufs, damit ein doppelt
 * gesendetes Ereignis (Doppelklick) nur einmal wirkt (Q-04).
 */
final readonly class IdempotencyGuard
{
    public function __construct(private int $remember = 50)
    {
    }

    public function isApplied(WorkflowInstance $instance, string $eventId): bool
    {
        return in_array($eventId, $this->applied($instance), true);
    }

    public function markApplied(WorkflowInstance $instance, string $eventId): void
    {
        $applied = [...$this->applied($instance), $eventId];
        $instance->context[ContextKeys::APPLIED_EVENTS] = array_slice($applied, -$this->remember);
    }

    /**
     * @return list<string>
     */
    private function applied(WorkflowInstance $instance): array
    {
        $applied = $instance->context[ContextKeys::APPLIED_EVENTS] ?? [];

        return is_array($applied) ? array_values(array_filter($applied, is_string(...))) : [];
    }
}
