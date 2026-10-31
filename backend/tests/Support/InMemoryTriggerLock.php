<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\TriggerLockInterface;

final class InMemoryTriggerLock implements TriggerLockInterface
{
    /** @var array<string, \DateTimeImmutable> */
    private array $locks = [];

    public function acquire(string $triggerKey, string $subjectKey, \DateTimeImmutable $now, \DateTimeImmutable $until): bool
    {
        $key = $triggerKey . "\0" . $subjectKey;
        if (isset($this->locks[$key]) && $this->locks[$key] > $now) {
            return false;
        }
        $this->locks[$key] = $until;

        return true;
    }

    public function release(string $triggerKey, string $subjectKey): void
    {
        unset($this->locks[$triggerKey . "\0" . $subjectKey]);
    }
}
