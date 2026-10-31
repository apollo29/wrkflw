<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Model\ModelUsage;

/** Welche Definitionen (alle Versionen) ein Datenmodell verwenden (F-140). */
interface ModelUsageQueryInterface
{
    /**
     * @return list<ModelUsage>
     */
    public function modelUsage(string $model): array;
}
