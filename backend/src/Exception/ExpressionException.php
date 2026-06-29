<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

/**
 * Wird geworfen, wenn ein "when"-Ausdruck syntaktisch ungueltig ist oder auf eine
 * nicht freigegebene Funktion/Variable zugreift. Kapselt die zugrunde liegende
 * Symfony-Exception, damit die Engine kontrolliert reagieren kann (kein Fatal).
 */
final class ExpressionException extends WorkflowException
{
}
