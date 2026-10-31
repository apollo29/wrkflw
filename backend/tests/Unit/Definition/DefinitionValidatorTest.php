<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Contracts\StepRuleInterface;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\ValidationCode;
use WorkflowEngine\Definition\ValidationError;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Exception\InvalidDefinitionException;

final class DefinitionValidatorTest extends TestCase
{
    public function testTheExampleFlowIsValid(): void
    {
        $report = $this->validator()->check(DefinitionParser::fromJson(DefinitionParserTest::fixture()));

        self::assertTrue($report->isValid(), implode("\n", $report->messages()));
    }

    public function testMissingStartStep(): void
    {
        $report = $this->validator()->check(self::def(['a' => []], start: 'x'));

        self::assertSame([ValidationCode::StartStepMissing], self::codes($report->errors));
    }

    public function testUnknownTargetIsReportedAtStepAndTransition(): void
    {
        $report = $this->validator()->check(self::def([
            'a' => ['transitions' => [['to' => 'b'], ['to' => 'zz']]],
            'b' => [],
        ]));

        $errors = $report->forStep('a');
        self::assertCount(1, $errors);
        self::assertSame(ValidationCode::TargetUnknown, $errors[0]->code);
        self::assertSame(1, $errors[0]->transitionIndex);
    }

    public function testUnreachableStep(): void
    {
        $report = $this->validator()->check(self::def(['a' => [], 'b' => []]));

        self::assertSame([ValidationCode::Unreachable], self::codes($report->forStep('b')));
    }

    public function testCycleWithoutExit(): void
    {
        $report = $this->validator()->check(self::def([
            'a' => ['transitions' => [['to' => 'b']]],
            'b' => ['transitions' => [['to' => 'a']]],
        ]));

        self::assertSame([ValidationCode::NoExit], self::codes($report->forStep('a')));
        self::assertSame([ValidationCode::NoExit], self::codes($report->forStep('b')));
    }

    public function testInteractiveStepNeedsATransitionWithEvent(): void
    {
        $report = $this->validator()->check(self::def([
            'a' => ['type' => 'interactive', 'transitions' => [['to' => 'b']]],
            'b' => [],
        ]));

        self::assertSame([ValidationCode::InteractiveWithoutEvent], self::codes($report->forStep('a')));
    }

    public function testNonInteractiveStepNeedsATransitionWithoutEvent(): void
    {
        $report = $this->validator()->check(self::def([
            'a' => ['type' => 'automatic', 'transitions' => [['to' => 'b', 'event' => 'submit']]],
            'b' => [],
        ]));

        self::assertSame([ValidationCode::NoEventlessExit], self::codes($report->forStep('a')));
    }

    public function testInvalidExpressionsAndNegativeDelay(): void
    {
        $report = $this->validator()->check(self::def([
            'a' => ['transitions' => [['to' => 'b', 'when' => 'context[']]],
            'b' => ['type' => 'timer', 'delaySeconds' => -5, 'until' => 'now +', 'transitions' => [['to' => 'c']]],
            'c' => [],
        ]));

        self::assertSame([ValidationCode::ExpressionInvalid], self::codes($report->forStep('a')));
        self::assertSame(
            [ValidationCode::DelayInvalid, ValidationCode::ExpressionInvalid],
            self::codes($report->forStep('b')),
        );
    }

    public function testAllErrorsAreReportedTogether(): void
    {
        $def = self::def([
            'a' => ['transitions' => [['to' => 'zz']]],
            'b' => ['type' => 'interactive', 'transitions' => [['to' => 'a']]],
        ]);

        try {
            $this->validator()->validate($def);
            self::fail('Keine Exception.');
        } catch (InvalidDefinitionException $e) {
            self::assertGreaterThanOrEqual(3, count($e->errors()));
            self::assertNotNull($e->report());
            self::assertSame($e->errors(), $e->report()->messages());
        }
    }

    public function testStepRulesAreApplied(): void
    {
        $rule = new class () implements StepRuleInterface {
            public function check(Step $step, WorkflowDefinition $definition): array
            {
                return $step->action === 'unknown'
                    ? [new ValidationError(ValidationCode::RuleViolation, 'Aktion unbekannt.', $step->name)]
                    : [];
            }
        };

        $report = (new DefinitionValidator(rules: [$rule]))->check(self::def(['a' => ['action' => 'unknown']]));

        self::assertSame(['Aktion unbekannt.'], $report->messages());
        self::assertSame('a', $report->errors[0]->step);
    }

    public function testReportSerialisesForTheApi(): void
    {
        $report = $this->validator()->check(self::def(['a' => [], 'b' => []]));

        self::assertSame(
            [['code' => 'unreachable', 'message' => "Schritt 'b' ist vom Start aus nicht erreichbar.", 'step' => 'b', 'transition' => null]],
            $report->toArray(),
        );
    }

    private function validator(): DefinitionValidator
    {
        return new DefinitionValidator(new SymfonyExpressionEvaluator());
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     */
    private static function def(array $steps, string $start = 'a'): WorkflowDefinition
    {
        return DefinitionParser::fromArray(['id' => 'test', 'startStep' => $start, 'steps' => $steps]);
    }

    /**
     * @param list<ValidationError> $errors
     *
     * @return list<ValidationCode>
     */
    private static function codes(array $errors): array
    {
        return array_map(static fn (ValidationError $e): ValidationCode => $e->code, $errors);
    }
}
