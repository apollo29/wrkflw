<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Exception\WorkflowException;

/**
 * Die REST-API hat die Anfrage abgelehnt (400, 401, 403, 422 …) oder eine unbrauchbare
 * Antwort geliefert. Eine Wiederholung hilft nicht. Die Meldung nennt den Statuscode, nie
 * den Inhalt der Antwort (kann Personendaten tragen).
 */
final class RestRequestException extends WorkflowException implements PermanentFailureInterface
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
