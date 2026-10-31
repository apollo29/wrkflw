<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/**
 * Ein Eintrag im Verlauf eines Durchlaufs. `detail` enthält nur Namen und technische
 * Angaben, nie eingegebene Werte, weil Eingaben Personendaten sein können (F-91).
 */
final readonly class HistoryEntry
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public HistoryKind $kind,
        public ?string $step,
        public array $detail,
        public \DateTimeImmutable $at,
    ) {
    }
}
