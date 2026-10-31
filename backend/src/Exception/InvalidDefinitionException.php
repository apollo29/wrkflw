<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

use WorkflowEngine\Definition\ValidationReport;

/**
 * Eine Definition ist fehlerhaft. Trägt immer ALLE gefundenen Fehler (F-06), damit
 * Editor und API sie gemeinsam anzeigen können.
 */
final class InvalidDefinitionException extends WorkflowException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        private readonly array $errors,
        private readonly ?ValidationReport $report = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $errors === []
                ? 'Ungültige Workflow-Definition.'
                : 'Ungültige Workflow-Definition: ' . implode('; ', $errors),
            0,
            $previous,
        );
    }

    public static function single(string $error): self
    {
        return new self([$error]);
    }

    public static function fromReport(ValidationReport $report): self
    {
        return new self($report->messages(), $report);
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Fehler je Schritt, falls die Prüfung des Validators sie geliefert hat. */
    public function report(): ?ValidationReport
    {
        return $this->report;
    }
}
