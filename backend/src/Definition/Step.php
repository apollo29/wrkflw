<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Exception\InvalidDefinitionException;
use WorkflowEngine\Instance\ContextKeys;

/**
 * Ein Knoten im Ablauf. `ui` beschreibt bei interaktiven Schritten die Oberfläche
 * (Titel, Felder, Knöpfe) und bleibt ein offenes Array, weil der Client es generisch
 * darstellt (F-50).
 */
final readonly class Step
{
    /** Feldtyp für reine Anzeige: nimmt keine Eingabe entgegen (F-51). */
    public const string FIELD_DISPLAY = 'display';

    /**
     * @param array<string, mixed> $config
     * @param list<Transition>     $transitions
     * @param array<string, mixed> $ui
     */
    public function __construct(
        public string $name,
        public StepType $type,
        public ?string $action = null,
        public array $config = [],
        public array $transitions = [],
        public ?int $delaySeconds = null,
        public ?string $untilExpr = null,
        public array $ui = [],
    ) {
    }

    /**
     * @param array<mixed> $d
     */
    public static function fromArray(string $name, array $d): self
    {
        $rawType = $d['type'] ?? StepType::Automatic->value;
        $type = is_string($rawType) ? StepType::tryFrom($rawType) : null;
        if ($type === null) {
            throw InvalidDefinitionException::single(sprintf(
                "Schritt '%s' hat einen unbekannten Typ. Erlaubt: %s.",
                $name,
                implode(', ', array_map(static fn (StepType $t): string => $t->value, StepType::cases())),
            ));
        }

        $action = $d['action'] ?? null;
        if ($action !== null && (!is_string($action) || $action === '')) {
            throw InvalidDefinitionException::single("Schritt '{$name}' hat eine ungültige Aktion.");
        }

        $delay = $d['delaySeconds'] ?? null;
        if ($delay !== null && !is_int($delay)) {
            throw InvalidDefinitionException::single("Schritt '{$name}' hat eine ungültige Wartezeit.");
        }

        $until = $d['until'] ?? null;
        if ($until !== null && !is_string($until)) {
            throw InvalidDefinitionException::single("Schritt '{$name}' hat einen ungültigen Zeitpunkt.");
        }

        $rawTransitions = $d['transitions'] ?? [];
        if (!is_array($rawTransitions)) {
            throw InvalidDefinitionException::single("Schritt '{$name}' hat ungültige Übergänge.");
        }
        $transitions = [];
        foreach ($rawTransitions as $entry) {
            if (!is_array($entry)) {
                throw InvalidDefinitionException::single("Schritt '{$name}' enthält einen Übergang, der kein Objekt ist.");
            }
            $transitions[] = Transition::fromArray($entry, $name);
        }

        return new self(
            name: $name,
            type: $type,
            action: $action,
            config: self::map($d['config'] ?? [], 'config', $name),
            transitions: $transitions,
            delaySeconds: $delay,
            untilExpr: $until,
            ui: self::map($d['ui'] ?? [], 'ui', $name),
        );
    }

    public function isInteractive(): bool
    {
        return $this->type === StepType::Interactive;
    }

    public function isTimer(): bool
    {
        return $this->type === StepType::Timer;
    }

    public function isAutomatic(): bool
    {
        return $this->type === StepType::Automatic;
    }

    /** Ohne Übergänge ist hier Schluss. */
    public function isTerminal(): bool
    {
        return $this->transitions === [];
    }

    /** «Ein Schritt zurück» ist nur erlaubt, wenn der Autor es eingeschaltet hat (F-56). */
    public function allowsBack(): bool
    {
        return ($this->ui['back'] ?? null) === true;
    }

    /**
     * Die Namen der Eingabefelder, die dieser Schritt tatsächlich anbietet. Anzeigefelder
     * und intern benannte Felder zählen nicht (ADR 6): sonst liesse sich über sie ein
     * Kontextwert überschreiben.
     *
     * @return list<string>
     */
    public function inputFieldNames(): array
    {
        $fields = $this->ui['fields'] ?? null;
        if (!is_array($fields)) {
            return [];
        }

        $names = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = $field['name'] ?? null;
            if (!is_string($name) || $name === '' || ContextKeys::isInternal($name)) {
                continue;
            }
            if (($field['type'] ?? null) === self::FIELD_DISPLAY) {
                continue;
            }
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['type' => $this->type->value];
        if ($this->action !== null) {
            $out['action'] = $this->action;
        }
        if ($this->config !== []) {
            $out['config'] = $this->config;
        }
        if ($this->delaySeconds !== null) {
            $out['delaySeconds'] = $this->delaySeconds;
        }
        if ($this->untilExpr !== null) {
            $out['until'] = $this->untilExpr;
        }
        if ($this->ui !== []) {
            $out['ui'] = $this->ui;
        }
        $out['transitions'] = array_map(static fn (Transition $t): array => $t->toArray(), $this->transitions);

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $raw, string $field, string $stepName): array
    {
        if (!is_array($raw)) {
            throw InvalidDefinitionException::single("Schritt '{$stepName}' hat ein ungültiges '{$field}'.");
        }

        $out = [];
        foreach ($raw as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }
}
