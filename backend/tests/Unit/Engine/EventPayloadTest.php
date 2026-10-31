<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\StepType;
use WorkflowEngine\Engine\EngineOptions;
use WorkflowEngine\Engine\EventPayloadFilter;
use WorkflowEngine\Engine\EventPayloadPolicy;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Tests\Support\EngineHarness;

/** ADR 6: Eingaben von aussen steuern die Engine nicht (F-101 bis F-103). */
final class EventPayloadTest extends TestCase
{
    private static function step(): Step
    {
        return new Step('s', StepType::Interactive, ui: ['fields' => [
            ['name' => 'bemerkung', 'type' => 'text'],
            ['name' => 'betrag', 'type' => 'display'],
            ['name' => '__parent', 'type' => 'text'],
            'kaputt',
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return ['bemerkung' => 'ok', 'betrag' => 0, 'fremd' => 1, '__awaitWorkflow' => 'x'];
    }

    public function testEnforceIsTheDefault(): void
    {
        self::assertSame(EventPayloadPolicy::Enforce, (new EngineOptions())->payloadPolicy);
    }

    public function testEnforceKeepsOnlyOfferedInputFields(): void
    {
        $result = (new EventPayloadFilter(EventPayloadPolicy::Enforce))->filter(self::step(), self::payload());

        self::assertSame(['bemerkung' => 'ok'], $result->payload);
        self::assertSame(['__awaitWorkflow', 'betrag', 'fremd'], $result->dropped);
        self::assertSame([], $result->wouldDrop);
    }

    public function testReportKeepsButNamesUndeclaredFields(): void
    {
        $result = (new EventPayloadFilter(EventPayloadPolicy::Report))->filter(self::step(), self::payload());

        self::assertSame(['bemerkung' => 'ok', 'betrag' => 0, 'fremd' => 1], $result->payload);
        self::assertSame(['__awaitWorkflow'], $result->dropped);
        self::assertSame(['betrag', 'fremd'], $result->wouldDrop);
    }

    public function testAllowStillDropsInternalKeys(): void
    {
        $result = (new EventPayloadFilter(EventPayloadPolicy::Allow))->filter(self::step(), self::payload());

        self::assertArrayNotHasKey('__awaitWorkflow', $result->payload);
        self::assertSame(['__awaitWorkflow'], $result->dropped);
    }

    public function testDroppedFieldsDoNotFailTheEventAndAreLoggedByNameOnly(): void
    {
        $h = new EngineHarness();
        $h->define([
            'id' => 'f', 'startStep' => 's',
            'steps' => [
                's' => ['type' => 'interactive', 'ui' => ['fields' => [['name' => 'bemerkung']]], 'transitions' => [['to' => 'e', 'event' => 'submit']]],
                'e' => [],
            ],
        ]);
        $id = $h->engine->start('f')->id;

        $instance = $h->engine->handleEvent($id, 'submit', ['bemerkung' => 'ok', 'fremd' => 'Wert', '__parent' => ['instanceId' => 'x']]);

        self::assertTrue($instance->isFinished());
        self::assertArrayNotHasKey('fremd', $instance->context);
        self::assertArrayNotHasKey('__parent', $instance->context);
        $rejected = array_values(array_filter($h->repo->findHistory($id), static fn ($e): bool => $e->kind === HistoryKind::EventPayloadRejected));
        self::assertCount(1, $rejected);
        self::assertSame(['event' => 'submit', 'dropped' => ['__parent', 'fremd'], 'wouldDrop' => []], $rejected[0]->detail);
    }

    public function testDuplicateEventIdsApplyOnlyOnce(): void
    {
        $h = new EngineHarness();
        $h->define([
            'id' => 'zweimal', 'startStep' => 'a',
            'steps' => [
                'a' => ['type' => 'interactive', 'transitions' => [['to' => 'b', 'event' => 'submit']]],
                'b' => ['type' => 'interactive', 'transitions' => [['to' => 'c', 'event' => 'submit']]],
                'c' => [],
            ],
        ]);
        $id = $h->engine->start('zweimal')->id;

        $h->engine->handleEvent($id, 'submit', [], 'klick-1');
        $instance = $h->engine->handleEvent($id, 'submit', [], 'klick-1');

        self::assertSame('b', $instance->currentStep);
        self::assertContains('event_duplicate', $h->repo->historyKinds($id));

        self::assertSame('c', $h->engine->handleEvent($id, 'submit', [], 'klick-2')->currentStep);
    }

    public function testALateDuplicateAfterCompletionIsANoOp(): void
    {
        $h = new EngineHarness();
        $h->define([
            'id' => 'einmal', 'startStep' => 'a',
            'steps' => ['a' => ['type' => 'interactive', 'transitions' => [['to' => 'b', 'event' => 'submit']]], 'b' => []],
        ]);
        $id = $h->engine->start('einmal')->id;
        $h->engine->handleEvent($id, 'submit', [], 'klick-1');

        $instance = $h->engine->handleEvent($id, 'submit', [], 'klick-1');

        self::assertTrue($instance->isFinished());
        self::assertSame('event_duplicate', $h->repo->lastHistory($id)->kind->value);
    }

    public function testOnlyTheNewestFiftyEventIdsAreKept(): void
    {
        $h = new EngineHarness();
        $h->define([
            'id' => 'viele', 'startStep' => 'a',
            'steps' => ['a' => ['type' => 'interactive', 'transitions' => [['to' => 'b', 'event' => 'weiter']]], 'b' => []],
        ]);
        $id = $h->engine->start('viele')->id;

        for ($i = 1; $i <= 60; $i++) {
            $h->engine->handleEvent($id, 'nichts', [], "e{$i}");
        }

        $applied = $h->reload($id)->context['__appliedEventIds'];
        self::assertIsArray($applied);
        self::assertCount(50, $applied);
        self::assertSame('e11', $applied[0]);
    }
}
