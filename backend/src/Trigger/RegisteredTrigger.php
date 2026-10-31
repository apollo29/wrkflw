<?php

declare(strict_types=1);

namespace WorkflowEngine\Trigger;

use WorkflowEngine\Contracts\TriggerInterface;

/** Ein Trigger mit Namen und Sperrfrist je Datensatz (null = keine). */
final readonly class RegisteredTrigger
{
    public function __construct(
        public TriggerInterface $trigger,
        public string $key,
        public ?int $lockSeconds = null,
    ) {
    }
}
