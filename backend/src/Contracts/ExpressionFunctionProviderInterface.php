<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Funktionen, die die Host-App ausdrücklich für Bedingungen freigibt (F-100).
 * Ausser diesen gibt es nur die eingebauten Zeitfunktionen `days()`, `hours()` und
 * `minutes()`.
 */
interface ExpressionFunctionProviderInterface
{
    /**
     * @return array<string, \Closure> Name => Funktion. Argumente kommen aus dem Ausdruck.
     */
    public function functions(): array;
}
