<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/** Filter für die Liste der Durchläufe (Reiter «Instanzen»). */
final readonly class InstanceFilter
{
    public function __construct(
        public ?InstanceStatus $status = null,
        public ?string $definitionId = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
