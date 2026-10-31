<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Rest\AdminTrigger;

/** Ablage der vom Admin eingerichteten Trigger. */
interface AdminTriggerRepositoryInterface
{
    public function findTrigger(string $id): ?AdminTrigger;

    /**
     * @return list<AdminTrigger>
     */
    public function allTriggers(): array;

    public function saveTrigger(AdminTrigger $trigger): void;

    public function deleteTrigger(string $id): void;

    public function markPolled(string $id, \DateTimeImmutable $at): void;
}
