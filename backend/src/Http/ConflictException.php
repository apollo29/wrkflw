<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use WorkflowEngine\Exception\WorkflowException;

/** Die Anfrage passt nicht zum aktuellen Stand (409), z. B. ID schon vergeben. */
final class ConflictException extends WorkflowException
{
    public function __construct(string $message, public readonly string $errorCode = 'conflict')
    {
        parent::__construct($message);
    }
}
