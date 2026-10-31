<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/** Eine Zeile der Instanzen-Liste, ohne Kontext (der kann Personendaten tragen). */
final readonly class InstanceSummary
{
    public function __construct(
        public string $id,
        public string $definitionId,
        public int $definitionVersion,
        public string $definitionName,
        public string $currentStep,
        public InstanceStatus $status,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?\DateTimeImmutable $wakeAt,
        public ?string $lastError,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
