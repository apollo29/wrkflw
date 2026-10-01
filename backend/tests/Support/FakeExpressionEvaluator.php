<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;

/**
 * Ein Evaluator mit fester Antwort, der festhaelt, womit er gerufen wurde.
 *
 * Eine benannte Klasse und keine anonyme im Test: die hielt das Protokoll
 * ueber eine Referenz-Eigenschaft, und PHPStan sah dort nur Schreibzugriffe
 * und einen `mixed`-Zugriff auf den Geltungsbereich. Mit der Form unten weiss
 * die Analyse, was in `aufrufe` steht.
 */
final class FakeExpressionEvaluator implements ExpressionEvaluatorInterface
{
    /** @var list<array{expression:string,scope:array<string,mixed>}> */
    public array $aufrufe = [];

    public function __construct(private readonly bool $antwort)
    {
    }

    public function evaluate(string $expression, array $scope): bool
    {
        $this->aufrufe[] = ['expression' => $expression, 'scope' => $scope];

        return $this->antwort;
    }

    public function evaluateValue(string $expression, array $scope): mixed
    {
        $this->aufrufe[] = ['expression' => $expression, 'scope' => $scope];

        return $this->antwort;
    }
}
