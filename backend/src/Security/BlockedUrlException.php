<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Exception\WorkflowException;

/** Eine Adresse ist nicht erlaubt: kein HTTPS, intern, oder nicht freigegeben (F-136). */
final class BlockedUrlException extends WorkflowException implements PermanentFailureInterface
{
}
