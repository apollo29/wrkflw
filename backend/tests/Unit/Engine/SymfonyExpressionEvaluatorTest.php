<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Contracts\ExpressionFunctionProviderInterface;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Exception\ExpressionException;
use WorkflowEngine\Tests\Support\FrozenClock;

final class SymfonyExpressionEvaluatorTest extends TestCase
{
    public function testComparesContextValues(): void
    {
        $e = new SymfonyExpressionEvaluator();

        self::assertTrue($e->evaluate("context['betrag'] > 1000", ['context' => ['betrag' => 1250]]));
        self::assertFalse($e->evaluate("context['status'] == 'offen'", ['context' => ['status' => 'zu']]));
    }

    public function testMissingKeysEvaluateToNull(): void
    {
        $e = new SymfonyExpressionEvaluator();

        self::assertTrue($e->evaluate("context['fehlt'] == null", ['context' => []]));
    }

    public function testNowComesFromTheClockAndDurationsAreBuiltIn(): void
    {
        $clock = new FrozenClock('2026-10-31 09:00:00');
        $e = new SymfonyExpressionEvaluator($clock);

        self::assertSame($clock->now()->getTimestamp(), $e->evaluateValue('now', ['context' => []]));
        self::assertSame(3 * 86400 + 2 * 3600 + 60, $e->evaluateValue('days(3) + hours(2) + minutes(1)', []));
        self::assertTrue($e->evaluate("context['frist'] < now + days(30)", [
            'context' => ['frist' => $clock->now()->modify('+10 days')->getTimestamp()],
        ]));
    }

    public function testNoBuiltInPhpFunctionsAreReachable(): void
    {
        $e = new SymfonyExpressionEvaluator();

        foreach (['constant("PHP_VERSION")', 'enum("X")', 'min(1, 2)'] as $expression) {
            try {
                $e->evaluateValue($expression, []);
                self::fail("Nicht gesperrt: {$expression}");
            } catch (ExpressionException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHostFunctionsMustBeReleasedExplicitly(): void
    {
        $provider = new class () implements ExpressionFunctionProviderInterface {
            public function functions(): array
            {
                return ['upper' => static fn (mixed $s): string => strtoupper(is_string($s) ? $s : '')];
            }
        };
        $e = new SymfonyExpressionEvaluator(providers: [$provider]);

        self::assertTrue($e->evaluate("upper(context['a']) == 'AB'", ['context' => ['a' => 'ab']]));
        $e->check("upper('x') == 'X'");
    }

    public function testCheckRejectsSyntaxErrorsAndUnknownNames(): void
    {
        $e = new SymfonyExpressionEvaluator();

        foreach (['context[', "context['a'] ==", 'unbekannt > 1', 'system("ls")'] as $expression) {
            try {
                $e->check($expression);
                self::fail("Nicht erkannt: {$expression}");
            } catch (ExpressionException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRuntimeErrorsBecomeExpressionExceptions(): void
    {
        $this->expectException(ExpressionException::class);
        (new SymfonyExpressionEvaluator())->evaluateValue('days("x")', []);
    }
}
