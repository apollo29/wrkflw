<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Exception\InvalidDefinitionException;

#[CoversClass(Step::class)]
final class StepTest extends TestCase
{
    public function testFromArrayParsesAutomaticStep(): void
    {
        $step = Step::fromArray('send_welcome', [
            'type' => 'automatic',
            'action' => 'send_email',
            'config' => ['to' => '{{email}}'],
            'transitions' => [['to' => 'await_profile']],
        ]);

        self::assertSame('send_welcome', $step->name);
        self::assertSame(Step::AUTOMATIC, $step->type);
        self::assertSame('send_email', $step->action);
        self::assertSame(['to' => '{{email}}'], $step->config);
        self::assertCount(1, $step->transitions);
        self::assertSame('await_profile', $step->transitions[0]->to);
    }

    public function testDefaultsToAutomaticWithoutType(): void
    {
        $step = Step::fromArray('s', []);

        self::assertSame(Step::AUTOMATIC, $step->type);
        self::assertNull($step->action);
        self::assertSame([], $step->config);
        self::assertSame([], $step->transitions);
    }

    public function testParsesTimerFields(): void
    {
        $step = Step::fromArray('wait', ['type' => 'timer', 'delaySeconds' => 60]);

        self::assertTrue($step->isTimer());
        self::assertSame(60, $step->delaySeconds);
    }

    public function testParsesInteractiveUi(): void
    {
        $step = Step::fromArray('await', [
            'type' => 'interactive',
            'ui' => ['title' => 'Profil', 'fields' => [['name' => 'phone']]],
        ]);

        self::assertTrue($step->isInteractive());
        self::assertSame('Profil', $step->ui['title']);
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        Step::fromArray('s', ['type' => 'banana']);
    }

    public function testInvalidTransitionStructureThrows(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        // transitions muss eine Liste von Objekten sein
        Step::fromArray('s', ['transitions' => ['not-an-array']]);
    }
}
