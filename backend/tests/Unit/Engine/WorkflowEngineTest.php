<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Engine\EngineOptions;
use WorkflowEngine\Exception\InvalidStateException;
use WorkflowEngine\Exception\MissingInputException;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Tests\Support\EngineHarness;

final class WorkflowEngineTest extends TestCase
{
    private EngineHarness $h;

    protected function setUp(): void
    {
        $this->h = new EngineHarness();
    }

    /**
     * @return array<string, mixed>
     */
    private static function simpleFlow(string $id = 'einfach'): array
    {
        return [
            'id' => $id,
            'startStep' => 'a',
            'steps' => [
                'a' => ['type' => 'automatic', 'transitions' => [['to' => 'b']]],
                'b' => [
                    'type' => 'interactive',
                    'ui' => ['fields' => [['name' => 'bemerkung', 'type' => 'text']]],
                    'transitions' => [['to' => 'c', 'event' => 'submit']],
                ],
                'c' => ['type' => 'automatic'],
            ],
        ];
    }

    public function testStartRunsAutomaticStepsUntilAnInteractiveStep(): void
    {
        $this->h->define(self::simpleFlow());

        $instance = $this->h->engine->start('einfach', ['x' => 1], 'antrag', 'A-1');

        self::assertSame(InstanceStatus::WaitingEvent, $instance->status);
        self::assertSame('b', $instance->currentStep);
        self::assertSame('antrag', $instance->subjectType);
        self::assertEquals($instance, $this->h->reload($instance->id));
        self::assertSame(['start', 'transition', 'wait_event'], $this->h->repo->historyKinds($instance->id));
    }

    public function testAnEventMovesOnAndTheFlowCompletes(): void
    {
        $this->h->define(self::simpleFlow());
        $id = $this->h->engine->start('einfach')->id;

        $instance = $this->h->engine->handleEvent($id, 'submit', ['bemerkung' => 'passt']);

        self::assertSame(InstanceStatus::Completed, $instance->status);
        self::assertSame('c', $instance->currentStep);
        self::assertSame('passt', $instance->context['bemerkung']);
        self::assertEquals($instance, $this->h->reload($id));
    }

    public function testAnEventWithoutMatchingTransitionOnlyStoresTheInput(): void
    {
        $this->h->define(self::simpleFlow());
        $id = $this->h->engine->start('einfach')->id;

        $instance = $this->h->engine->handleEvent($id, 'abbrechen', ['bemerkung' => 'x']);

        self::assertSame(InstanceStatus::WaitingEvent, $instance->status);
        self::assertSame('b', $instance->currentStep);
        self::assertSame('x', $this->h->reload($id)->context['bemerkung']);
    }

    public function testMissingInputsPreventTheStartAndAreNamedTogether(): void
    {
        $this->h->define(self::simpleFlow() + ['inputs' => [
            ['name' => 'antrag_id', 'required' => true],
            ['name' => 'betrag', 'required' => true],
            ['name' => 'notiz'],
        ]]);

        try {
            $this->h->engine->start('einfach', ['betrag' => 0]);
            self::fail('Keine Exception.');
        } catch (MissingInputException $e) {
            self::assertSame(['antrag_id'], $e->missing);
        }
        self::assertSame([], $this->h->repo->allInstances());
    }

    public function testUnknownDefinitionAndInstance(): void
    {
        try {
            $this->h->engine->start('gibt-es-nicht');
            self::fail('Keine Exception.');
        } catch (NotFoundException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(NotFoundException::class);
        $this->h->engine->handleEvent('nope', 'submit');
    }

    public function testEventsOnFinishedOrNonInteractiveInstancesAreRejected(): void
    {
        $this->h->define(self::simpleFlow());
        $id = $this->h->engine->start('einfach')->id;
        $this->h->engine->handleEvent($id, 'submit');

        try {
            $this->h->engine->handleEvent($id, 'submit');
            self::fail('Ereignis auf beendetem Durchlauf angenommen.');
        } catch (InvalidStateException) {
            self::addToAssertionCount(1);
        }

        $this->h->define([
            'id' => 'timer', 'startStep' => 't',
            'steps' => ['t' => ['type' => 'timer', 'delaySeconds' => 60, 'transitions' => [['to' => 'e']]], 'e' => []],
        ]);
        $timerId = $this->h->engine->start('timer')->id;

        $this->expectException(InvalidStateException::class);
        $this->h->engine->handleEvent($timerId, 'submit');
    }

    public function testConditionsChooseTheBranch(): void
    {
        $this->h->define([
            'id' => 'weiche', 'startStep' => 'a',
            'steps' => [
                'a' => ['transitions' => [
                    ['to' => 'gross', 'when' => "context['betrag'] > 1000"],
                    ['to' => 'klein'],
                ]],
                'gross' => [], 'klein' => [],
            ],
        ]);

        self::assertSame('gross', $this->h->engine->start('weiche', ['betrag' => 1250])->currentStep);
        self::assertSame('klein', $this->h->engine->start('weiche', ['betrag' => 500])->currentStep);
        self::assertSame('klein', $this->h->engine->start('weiche')->currentStep);
    }

    public function testTimerWaitsUntilItsTimeAndThenContinues(): void
    {
        $this->h->define([
            'id' => 'warten', 'startStep' => 't',
            'steps' => ['t' => ['type' => 'timer', 'delaySeconds' => 3600, 'transitions' => [['to' => 'e']]], 'e' => []],
        ]);
        $instance = $this->h->engine->start('warten');

        self::assertSame(InstanceStatus::WaitingTimer, $instance->status);
        self::assertEquals($this->h->clock->now()->modify('+3600 seconds'), $instance->wakeAt);

        $this->h->clock->advance('+59 minutes');
        $this->h->engine->advance($instance);
        self::assertSame(InstanceStatus::WaitingTimer, $this->h->reload($instance->id)->status);

        $this->h->clock->advance('+1 minute');
        $this->h->engine->advance($this->h->reload($instance->id));
        self::assertSame(InstanceStatus::Completed, $this->h->reload($instance->id)->status);
    }

    public function testTimerUntilUsesAnExpression(): void
    {
        $this->h->define([
            'id' => 'bis', 'startStep' => 't',
            'steps' => ['t' => ['type' => 'timer', 'until' => "context['frist'] - days(1)", 'transitions' => [['to' => 'e']]], 'e' => []],
        ]);
        $frist = $this->h->clock->now()->modify('+10 days');

        $instance = $this->h->engine->start('bis', ['frist' => $frist->getTimestamp()]);

        self::assertSame($frist->modify('-1 day')->getTimestamp(), $instance->wakeAt?->getTimestamp());
    }

    public function testAStepWithOnlyEventTransitionsFailsInsteadOfSilentlyCompleting(): void
    {
        $this->h->define([
            'id' => 'sackgasse', 'startStep' => 'a',
            'steps' => ['a' => ['transitions' => [['to' => 'b', 'event' => 'submit']]], 'b' => []],
        ], validate: false);

        $instance = $this->h->engine->start('sackgasse');

        self::assertSame(InstanceStatus::Failed, $instance->status);
        self::assertStringContainsString('keinen Ausgang ohne Ereignis', (string) $instance->lastError);
    }

    public function testTheStepLimitStopsEndlessLoops(): void
    {
        $h = new EngineHarness(new EngineOptions(stepLimit: 10));
        $h->define([
            'id' => 'schleife', 'startStep' => 'a',
            'steps' => ['a' => ['transitions' => [['to' => 'b']]], 'b' => ['transitions' => [['to' => 'a']]]],
        ], validate: false);

        $instance = $h->engine->start('schleife');

        self::assertSame(InstanceStatus::Failed, $instance->status);
        self::assertStringContainsString('Schritt-Limit', (string) $instance->lastError);
    }

    public function testRunningInstancesStayOnTheirVersion(): void
    {
        $this->h->define(self::simpleFlow(), version: 1);
        $id = $this->h->engine->start('einfach')->id;

        $this->h->define([
            'id' => 'einfach',
            'startStep' => 'a',
            'steps' => [
                'a' => ['transitions' => [['to' => 'b']]],
                'b' => ['type' => 'interactive', 'transitions' => [['to' => 'neu', 'event' => 'submit']]],
                'neu' => [],
            ],
        ], version: 2);

        self::assertSame(2, $this->h->engine->start('einfach')->definitionVersion);
        self::assertSame('c', $this->h->engine->handleEvent($id, 'submit')->currentStep);
    }

    public function testHistoryContainsNamesButNeverValues(): void
    {
        $this->h->define(self::simpleFlow());
        $id = $this->h->engine->start('einfach', ['name' => 'Geheim Muster'])->id;
        $this->h->engine->handleEvent($id, 'submit', ['bemerkung' => 'Geheime Bemerkung', 'fremd' => 'Geheim']);

        $all = json_encode(array_map(static fn ($e) => [$e->step, $e->detail], $this->h->repo->findHistory($id)), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('Geheim', $all);
        self::assertStringContainsString('bemerkung', $all);
        self::assertStringContainsString('fremd', $all);
    }

    public function testOptionsAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EngineOptions(maxAttempts: 0);
    }
}
