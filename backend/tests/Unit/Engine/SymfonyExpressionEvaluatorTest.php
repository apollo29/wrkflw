<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Exception\ExpressionException;

#[CoversClass(SymfonyExpressionEvaluator::class)]
final class SymfonyExpressionEvaluatorTest extends TestCase
{
    private SymfonyExpressionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new SymfonyExpressionEvaluator();
    }

    public function testComparisons(): void
    {
        $scope = ['context' => ['amount' => 150]];

        self::assertTrue($this->evaluator->evaluate("context['amount'] > 100", $scope));
        self::assertFalse($this->evaluator->evaluate("context['amount'] > 1000", $scope));
        self::assertTrue($this->evaluator->evaluate("context['amount'] == 150", $scope));
    }

    public function testBooleanLogic(): void
    {
        $scope = ['context' => ['vip' => true, 'amount' => 1500]];

        self::assertTrue($this->evaluator->evaluate("context['vip'] and context['amount'] > 1000", $scope));
        self::assertFalse($this->evaluator->evaluate("context['vip'] and context['amount'] > 2000", $scope));
        self::assertTrue($this->evaluator->evaluate("context['vip'] or context['amount'] > 2000", $scope));
    }

    public function testDateComparisonWithNow(): void
    {
        $now = 1_000_000;
        $scope = ['context' => ['dueDate' => 999_000], 'now' => $now];

        self::assertTrue($this->evaluator->evaluate('context[\'dueDate\'] < now', $scope));
        self::assertFalse($this->evaluator->evaluate('context[\'dueDate\'] > now', $scope));
    }

    public function testNowDefaultsToCurrentTimeWhenAbsent(): void
    {
        $value = $this->evaluator->evaluateValue('now', ['context' => []]);

        self::assertIsInt($value);
        self::assertGreaterThan(0, $value);
    }

    public function testWhitelistedDurationFunctions(): void
    {
        self::assertSame(172_800, $this->evaluator->evaluateValue('days(2)', ['context' => []]));
        self::assertSame(7_200, $this->evaluator->evaluateValue('hours(2)', ['context' => []]));
        self::assertSame(120, $this->evaluator->evaluateValue('minutes(2)', ['context' => []]));
    }

    public function testDurationFunctionsComposeWithNow(): void
    {
        $scope = ['context' => ['ts' => 100], 'now' => 100 + 3 * 86_400 + 1];

        self::assertTrue($this->evaluator->evaluate("context['ts'] < now - days(3)", $scope));
    }

    public function testMissingContextKeyIsGracefulNotFatal(): void
    {
        $scope = ['context' => ['a' => 1]];

        // Kein Fatal/keine Warnung: fehlender Key wertet zu null aus.
        self::assertFalse($this->evaluator->evaluate("context['missing'] == true", $scope));
        self::assertNull($this->evaluator->evaluateValue("context['missing']", $scope));
    }

    public function testUnknownVariableThrowsControlled(): void
    {
        $this->expectException(ExpressionException::class);
        $this->evaluator->evaluate('foo > 1', ['context' => []]);
    }

    public function testSyntaxErrorThrowsControlled(): void
    {
        $this->expectException(ExpressionException::class);
        $this->evaluator->evaluate('1 +', ['context' => []]);
    }

    public function testBuiltinConstantFunctionIsDisabled(): void
    {
        // Sandbox: die eingebaute constant()-Funktion darf NICHT verfuegbar sein.
        $this->expectException(ExpressionException::class);
        $this->evaluator->evaluateValue("constant('PHP_VERSION')", ['context' => []]);
    }

    public function testArbitraryPhpFunctionIsDisabled(): void
    {
        $this->expectException(ExpressionException::class);
        $this->evaluator->evaluateValue("system('echo hi')", ['context' => []]);
    }

    public function testCustomWhitelistedFunctionCanBeRegistered(): void
    {
        $double = new ExpressionFunction(
            'double',
            static fn (string $x): string => "(2 * {$x})",
            static fn (array $values, int $x): int => 2 * $x,
        );

        $evaluator = new SymfonyExpressionEvaluator([$double]);

        self::assertSame(42, $evaluator->evaluateValue('double(21)', ['context' => []]));
    }

    public function testEvaluateCastsResultToBool(): void
    {
        self::assertTrue($this->evaluator->evaluate('1', ['context' => []]));
        self::assertFalse($this->evaluator->evaluate('0', ['context' => []]));
        self::assertTrue($this->evaluator->evaluate('true', ['context' => []]));
    }
}
