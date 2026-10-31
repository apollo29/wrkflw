<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Ein Fehler einer Definition, wenn möglich an Schritt und Übergang gebunden, damit der
 * Editor ihn dort zeigt, wo er entsteht (9.14 «Prüfung»).
 */
final readonly class ValidationError
{
    public function __construct(
        public ValidationCode $code,
        public string $message,
        public ?string $step = null,
        public ?int $transitionIndex = null,
    ) {
    }

    /**
     * @return array{code: string, message: string, step: ?string, transition: ?int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'message' => $this->message,
            'step' => $this->step,
            'transition' => $this->transitionIndex,
        ];
    }
}
