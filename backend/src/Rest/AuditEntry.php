<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/**
 * Ein Eintrag im Protokoll der Datenquellen (F-145): wer, wann, was. `detail` nennt
 * geänderte Eigenschaften, nie Zugangsdaten oder Werte aus Beispielantworten.
 */
final readonly class AuditEntry
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public Actor $actor,
        public string $action,
        public string $objectType,
        public string $objectId,
        public array $detail = [],
    ) {
    }
}
