<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Exception\WorkflowException;

/** Schlüssel fehlt, ist ungültig, oder ein verschlüsselter Text ist manipuliert. */
final class SecretException extends WorkflowException implements PermanentFailureInterface
{
}
