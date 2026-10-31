<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Rest\AuditEntry;

/** Protokoll der Änderungen an Datenquellen (F-145). */
interface AuditLogInterface
{
    public function record(AuditEntry $entry): void;

    /**
     * Neueste zuerst.
     *
     * @return list<AuditEntry>
     */
    public function entries(?string $objectType = null, ?string $objectId = null, int $limit = 100): array;
}
