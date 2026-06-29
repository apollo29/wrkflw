<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Exception\InvalidDefinitionException;

/**
 * Eine Kante im Workflow-Graphen.
 *
 *  - $to:    Ziel-Step.
 *  - $when:  Expression (z. B. "context['amount'] > 100"), die wahr sein muss.
 *  - $event: Optional. Nur fuer interaktive Schritte: diese Transition feuert
 *            erst, wenn ein Event mit diesem Namen eintrifft.
 */
final class Transition
{
    public function __construct(
        public readonly string $to,
        public readonly string $when = 'true',
        public readonly ?string $event = null,
    ) {
    }

    /**
     * @param array<string,mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $to = $d['to'] ?? null;
        if (!is_string($to) || $to === '') {
            throw InvalidDefinitionException::single("Transition ohne gueltiges 'to'-Ziel.");
        }

        $when = $d['when'] ?? 'true';
        if (!is_string($when) || $when === '') {
            throw InvalidDefinitionException::single("Transition '{$to}' hat ein ungueltiges 'when'.");
        }

        $event = $d['event'] ?? null;
        if ($event !== null && !is_string($event)) {
            throw InvalidDefinitionException::single("Transition '{$to}' hat ein ungueltiges 'event'.");
        }

        return new self(to: $to, when: $when, event: $event);
    }
}
