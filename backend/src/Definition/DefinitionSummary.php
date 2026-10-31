<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Eine Zeile der Übersicht «Workflows» (E-60 bis E-66): die neueste Version eines Ablaufs
 * mit Zählern über alle Versionen und der Struktur für das Vorschaubild.
 *
 * @phpstan-import-type Structure from DefinitionStructure
 */
final readonly class DefinitionSummary
{
    /**
     * @param Structure|null $structure null, wenn die gespeicherte Definition nicht lesbar ist
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $version,
        public DefinitionStatus $status,
        public \DateTimeImmutable $updatedAt,
        public int $runningInstances,
        public int $totalInstances,
        public int $olderVersions,
        public ?array $structure,
    ) {
    }
}
