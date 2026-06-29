<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

/**
 * Wird geworfen, wenn eine Workflow-Definition strukturell oder semantisch
 * ungueltig ist (z. B. fehlender Start-Step, unbekanntes Transition-Ziel,
 * unerreichbare Steps, Zyklus ohne Ausgang).
 *
 * Kann mehrere einzelne Fehlermeldungen buendeln.
 */
final class InvalidDefinitionException extends WorkflowException
{
    /** @param list<string> $errors */
    public function __construct(
        private readonly array $errors,
        ?\Throwable $previous = null,
    ) {
        $message = $errors === []
            ? 'Ungueltige Workflow-Definition.'
            : 'Ungueltige Workflow-Definition: ' . implode('; ', $errors);

        parent::__construct($message, 0, $previous);
    }

    public static function single(string $error): self
    {
        return new self([$error]);
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
