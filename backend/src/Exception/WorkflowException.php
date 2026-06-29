<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

/**
 * Basis-Exception der Workflow-Engine. Alle spezifischen Fehler erben hiervon,
 * damit die Host-App sie gebuendelt fangen kann.
 */
class WorkflowException extends \RuntimeException
{
}
