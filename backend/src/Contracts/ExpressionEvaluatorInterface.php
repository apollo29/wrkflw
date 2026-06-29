<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Auswertung von Transitions-Bedingungen ("when").
 * Default: SymfonyExpressionEvaluator (sandboxed, ohne Ausfuehrung beliebigen Codes).
 * Austauschbar, falls eine andere Ausdruckssprache gewuenscht ist.
 */
interface ExpressionEvaluatorInterface
{
    /**
     * Wertet einen Ausdruck als Boolean aus (fuer Transitions-Bedingungen).
     *
     * @param array<string,mixed> $scope z. B. ['context' => [...], 'now' => 173...]
     *
     * @throws \WorkflowEngine\Exception\ExpressionException bei ungueltigem Ausdruck
     */
    public function evaluate(string $expression, array $scope): bool;

    /**
     * Wertet einen Ausdruck als beliebigen Wert aus (z. B. fuer einen Timer-"until",
     * der einen Unix-Timestamp liefern soll).
     *
     * @param array<string,mixed> $scope
     *
     * @throws \WorkflowEngine\Exception\ExpressionException bei ungueltigem Ausdruck
     */
    public function evaluateValue(string $expression, array $scope): mixed;
}
