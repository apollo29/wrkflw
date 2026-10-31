<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Exception\WorkflowException;

/** Ein REST-Datenmodell oder ein Trigger ist falsch beschrieben. */
final class RestSpecException extends WorkflowException implements PermanentFailureInterface
{
}
