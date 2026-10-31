<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Exception\MissingInputException;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\WorkflowInstance;

/** Startet einen Ablauf, etwa aus einem anderen Ablauf heraus (F-20, F-39). */
interface WorkflowStarterInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @throws NotFoundException
     * @throws MissingInputException
     */
    public function start(
        string $definitionId,
        array $context = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): WorkflowInstance;
}
