<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

/**
 * Der Start scheitert, weil deklarierte Pflichtangaben fehlen.
 *
 * Traegt ALLE fehlenden Namen, nicht nur die erste: sonst ist die Behebung
 * drei Anlaeufe statt einem.
 */
final class MissingInputException extends WorkflowException
{
    /** @param list<string> $missing */
    public function __construct(
        string $definitionId,
        public readonly array $missing,
    ) {
        parent::__construct(sprintf(
            "Workflow '%s' kann nicht starten: es fehlen die Angaben %s.",
            $definitionId,
            implode(', ', $missing),
        ));
    }
}
