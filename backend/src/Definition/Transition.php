<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Exception\InvalidDefinitionException;

/**
 * Ein Übergang zum nächsten Schritt, optional an eine Bedingung (`when`) und bei
 * interaktiven Schritten an ein Ereignis (`event`) gebunden.
 */
final readonly class Transition
{
    public function __construct(
        public string $to,
        public string $when = 'true',
        public ?string $event = null,
        public ?string $label = null,
    ) {
    }

    /**
     * @param array<mixed> $d
     */
    public static function fromArray(array $d, string $stepName): self
    {
        $to = $d['to'] ?? null;
        if (!is_string($to) || $to === '') {
            throw InvalidDefinitionException::single("Schritt '{$stepName}' hat einen Übergang ohne Ziel.");
        }

        $when = $d['when'] ?? 'true';
        if (!is_string($when) || trim($when) === '') {
            throw InvalidDefinitionException::single("Schritt '{$stepName}': Übergang nach '{$to}' hat eine ungültige Bedingung.");
        }

        $event = $d['event'] ?? null;
        if ($event !== null && (!is_string($event) || $event === '')) {
            throw InvalidDefinitionException::single("Schritt '{$stepName}': Übergang nach '{$to}' hat ein ungültiges Ereignis.");
        }

        $label = $d['label'] ?? null;

        return new self($to, $when, $event, is_string($label) && $label !== '' ? $label : null);
    }

    public function isConditional(): bool
    {
        return trim($this->when) !== 'true';
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = ['to' => $this->to];
        if ($this->when !== 'true') {
            $out['when'] = $this->when;
        }
        if ($this->event !== null) {
            $out['event'] = $this->event;
        }
        if ($this->label !== null) {
            $out['label'] = $this->label;
        }

        return $out;
    }
}
