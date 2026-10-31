<?php

declare(strict_types=1);

namespace WorkflowEngine\Trigger;

/** Ein Durchlauf, den ein Trigger starten möchte. */
final readonly class TriggerStart
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $definitionId,
        public array $context,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
    ) {
    }
}
