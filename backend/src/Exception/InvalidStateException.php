<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

/**
 * Die Operation passt nicht zum Zustand des Durchlaufs, etwa ein Ereignis an einen
 * beendeten Durchlauf oder ein Rücksprung, wo es keinen gibt.
 */
final class InvalidStateException extends WorkflowException
{
}
