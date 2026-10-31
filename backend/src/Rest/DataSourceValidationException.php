<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Exception\WorkflowException;

/** Eine Eingabe im Reiter «Datenquellen» ist ungültig; Fehler je Feld für das Formular. */
final class DataSourceValidationException extends WorkflowException
{
    /**
     * @param array<string, list<string>> $errors Feld => Meldungen
     */
    public function __construct(public readonly array $errors)
    {
        $parts = [];
        foreach ($errors as $field => $messages) {
            $parts[] = $field . ': ' . implode(' ', $messages);
        }
        parent::__construct('Ungültige Eingabe – ' . implode('; ', $parts));
    }
}
