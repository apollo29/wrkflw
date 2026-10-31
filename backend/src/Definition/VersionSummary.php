<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/** Eine Version eines Ablaufs mit ihren Durchläufen und ob sie entfernt werden darf (E-65, E-66). */
final readonly class VersionSummary
{
    public function __construct(
        public int $version,
        public string $name,
        public DefinitionStatus $status,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
        public int $runningInstances,
        public int $totalInstances,
        public ?VersionDeleteBlock $deleteBlockedBy,
    ) {
    }

    public function isDeletable(): bool
    {
        return $this->deleteBlockedBy === null;
    }
}
