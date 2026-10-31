<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Exception\ExpressionException;

/**
 * Wertet Bedingungen und Zeitangaben aus. Die einzige Stelle, an der Ausdrücke laufen;
 * beliebiger Programmcode ist nie ausführbar (F-100).
 *
 * Im Scope stehen `context` (die Daten des Durchlaufs) und `now` (Unix-Zeit).
 */
interface ExpressionEvaluatorInterface
{
    /**
     * @param array<string, mixed> $scope
     *
     * @throws ExpressionException
     */
    public function evaluate(string $expression, array $scope): bool;

    /**
     * @param array<string, mixed> $scope
     *
     * @throws ExpressionException
     */
    public function evaluateValue(string $expression, array $scope): mixed;
}
