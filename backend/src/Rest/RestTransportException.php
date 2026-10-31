<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Exception\WorkflowException;

/**
 * Technischer Fehler einer REST-Anfrage: Netz, Zeitüberschreitung, 5xx, 429. Die Engine
 * wiederholt den Schritt nach Q-01 (F-132). Meldungen nennen nie Zugangsdaten oder Inhalte.
 */
final class RestTransportException extends WorkflowException
{
}
