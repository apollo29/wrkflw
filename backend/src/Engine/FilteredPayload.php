<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

/** Ergebnis der Eingabe-Filterung: was übernommen wird und welche Namen auffielen. */
final readonly class FilteredPayload
{
    /**
     * @param array<string, mixed> $payload   übernommene Werte
     * @param list<string>         $dropped   verworfene Feldnamen
     * @param list<string>         $wouldDrop unter «Report» übernommen, unter «Enforce» verworfen
     */
    public function __construct(
        public array $payload,
        public array $dropped,
        public array $wouldDrop,
    ) {
    }
}
