<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\ContextKeys;

/**
 * Die Grenze zwischen Aussenwelt und Kontext eines Durchlaufs (ADR 6).
 *
 * Zwei Ebenen: engine-interne Schlüssel (`__…`) werden immer verworfen, sonst liessen
 * sich Idempotenz und Verknüpfungen aushebeln. Je nach Stufe zusätzlich alles, was der
 * Schritt nicht als Eingabefeld anbietet. Verworfen wird, nicht abgelehnt: ein Feld
 * eines älteren Clients soll den Schritt nicht blockieren (F-103).
 */
final readonly class EventPayloadFilter
{
    public function __construct(private EventPayloadPolicy $policy = EventPayloadPolicy::Enforce)
    {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public function filter(Step $step, array $payload): FilteredPayload
    {
        $kept = [];
        $dropped = [];
        foreach ($payload as $key => $value) {
            $name = (string) $key;
            if (ContextKeys::isInternal($name)) {
                $dropped[] = $name;
                continue;
            }
            $kept[$name] = $value;
        }

        if ($this->policy === EventPayloadPolicy::Allow) {
            return new FilteredPayload($kept, $dropped, []);
        }

        $undeclared = array_keys(array_diff_key($kept, array_flip($step->inputFieldNames())));

        if ($this->policy === EventPayloadPolicy::Report) {
            return new FilteredPayload($kept, $dropped, $undeclared);
        }

        return new FilteredPayload(
            array_diff_key($kept, array_flip($undeclared)),
            [...$dropped, ...$undeclared],
            [],
        );
    }
}
