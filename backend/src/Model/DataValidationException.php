<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Exception\WorkflowException;

/** Regeln des Datenmodells haben die Änderung abgelehnt; Fehler je Feld (F-125). */
final class DataValidationException extends WorkflowException implements PermanentFailureInterface
{
    /**
     * @param array<string, list<string>> $errors Feldname => Meldungen
     */
    public function __construct(public readonly string $model, public readonly array $errors)
    {
        $parts = [];
        foreach ($errors as $field => $messages) {
            $parts[] = $field . ': ' . implode(' ', $messages);
        }
        parent::__construct("Änderung an '{$model}' abgelehnt – " . implode('; ', $parts));
    }
}
