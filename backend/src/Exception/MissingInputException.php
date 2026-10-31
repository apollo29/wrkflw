<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

/**
 * Beim Start fehlen Pflichtwerte. Es entsteht kein Durchlauf, und alle fehlenden
 * Werte werden auf einmal genannt (F-21).
 */
final class MissingInputException extends WorkflowException
{
    /**
     * @param list<string> $missing
     */
    public function __construct(string $definitionId, public readonly array $missing)
    {
        parent::__construct(sprintf(
            "Workflow '%s' kann nicht starten: es fehlen die Angaben %s.",
            $definitionId,
            implode(', ', $missing),
        ));
    }
}
