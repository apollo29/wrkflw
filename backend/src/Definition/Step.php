<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Exception\InvalidDefinitionException;

/**
 * Ein Knoten im Workflow.
 *
 * Typen:
 *  - automatic   : laeuft im Hintergrund durch (fuehrt $action aus, dann Transitionen).
 *  - interactive : haelt an und wartet auf ein Frontend-Event.
 *  - timer       : wartet bis zu einem Zeitpunkt (siehe $delaySeconds / $untilExpr),
 *                  der Cron-Runner weckt die Instanz dann auf.
 */
final class Step
{
    public const AUTOMATIC = 'automatic';
    public const INTERACTIVE = 'interactive';
    public const TIMER = 'timer';

    private const TYPES = [self::AUTOMATIC, self::INTERACTIVE, self::TIMER];

    /**
     * @param list<Transition>    $transitions
     * @param array<string,mixed> $config
     * @param array<string,mixed> $ui
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $action = null,
        public readonly array $config = [],
        public readonly array $transitions = [],
        public readonly ?int $delaySeconds = null,
        public readonly ?string $untilExpr = null,
        public readonly array $ui = [],
    ) {
    }

    /**
     * @param array<string,mixed> $d
     */
    public static function fromArray(string $name, array $d): self
    {
        $type = $d['type'] ?? self::AUTOMATIC;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw InvalidDefinitionException::single(
                "Step '{$name}' hat einen unbekannten Typ. Erlaubt: " . implode(', ', self::TYPES) . '.'
            );
        }

        $action = $d['action'] ?? null;
        if ($action !== null && !is_string($action)) {
            throw InvalidDefinitionException::single("Step '{$name}' hat eine ungueltige 'action'.");
        }

        $config = self::assocArray($d['config'] ?? [], 'config', $name);
        $ui = self::assocArray($d['ui'] ?? [], 'ui', $name);

        $delaySeconds = $d['delaySeconds'] ?? null;
        if ($delaySeconds !== null && !is_int($delaySeconds)) {
            throw InvalidDefinitionException::single("Step '{$name}' hat ein ungueltiges 'delaySeconds'.");
        }

        $until = $d['until'] ?? null;
        if ($until !== null && !is_string($until)) {
            throw InvalidDefinitionException::single("Step '{$name}' hat ein ungueltiges 'until'.");
        }

        return new self(
            name: $name,
            type: $type,
            action: $action,
            config: $config,
            transitions: self::parseTransitions($name, $d['transitions'] ?? []),
            delaySeconds: $delaySeconds,
            untilExpr: $until,
            ui: $ui,
        );
    }

    public function isInteractive(): bool
    {
        return $this->type === self::INTERACTIVE;
    }

    public function isTimer(): bool
    {
        return $this->type === self::TIMER;
    }

    public function isTerminal(): bool
    {
        return $this->transitions === [];
    }

    /**
     * Normalisiert ein JSON-Objekt zu einem assoziativen Array mit String-Keys.
     *
     * @return array<string,mixed>
     */
    private static function assocArray(mixed $raw, string $field, string $stepName): array
    {
        if (!is_array($raw)) {
            throw InvalidDefinitionException::single("Step '{$stepName}' hat ein ungueltiges '{$field}'.");
        }

        $out = [];
        foreach ($raw as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @return list<Transition>
     */
    private static function parseTransitions(string $name, mixed $raw): array
    {
        if (!is_array($raw)) {
            throw InvalidDefinitionException::single("Step '{$name}' hat ungueltige 'transitions'.");
        }

        $transitions = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                throw InvalidDefinitionException::single(
                    "Step '{$name}' enthaelt eine Transition, die kein Objekt ist."
                );
            }
            /** @var array<string,mixed> $entry */
            $transitions[] = Transition::fromArray($entry);
        }

        return $transitions;
    }
}
