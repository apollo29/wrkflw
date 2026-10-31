<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Exception\WorkflowException;

/** Ein Wert passt nicht zum Typ seines Feldes (F-119). Nennt das Feld. */
final class InvalidFieldValueException extends WorkflowException implements PermanentFailureInterface
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
