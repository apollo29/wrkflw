<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Trigger\RegisteredTrigger;

/**
 * Liefert dem Hintergrundlauf bei jedem Durchgang die fälligen Trigger, etwa die vom Admin
 * eingerichteten (F-142). So gelten Änderungen ohne Neustart.
 */
interface TriggerSourceInterface
{
    /**
     * @return list<RegisteredTrigger>
     */
    public function triggers(\DateTimeImmutable $now): array;
}
