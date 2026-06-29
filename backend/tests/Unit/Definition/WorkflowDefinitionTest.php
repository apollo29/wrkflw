<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\InvalidDefinitionException;
use WorkflowEngine\Exception\WorkflowException;

#[CoversClass(WorkflowDefinition::class)]
final class WorkflowDefinitionTest extends TestCase
{
    /** @return array<string,mixed> */
    private function onboarding(): array
    {
        $path = dirname(__DIR__, 3) . '/examples/onboarding.json';
        $json = file_get_contents($path);
        self::assertIsString($json);

        /** @var array<string,mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    public function testParsesOnboardingExample(): void
    {
        $def = WorkflowDefinition::fromArray($this->onboarding());

        self::assertSame('onboarding', $def->id);
        self::assertSame(1, $def->version);
        self::assertSame('send_welcome', $def->startStep);
        self::assertCount(7, $def->steps);
        self::assertSame(Step::INTERACTIVE, $def->step('await_profile')->type);
        self::assertSame(Step::TIMER, $def->step('wait_3_days')->type);
    }

    public function testStepLookupUnknownThrows(): void
    {
        $def = WorkflowDefinition::fromArray($this->onboarding());

        $this->expectException(WorkflowException::class);
        $def->step('does_not_exist');
    }

    public function testMissingStartStepThrows(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        WorkflowDefinition::fromArray([
            'id' => 'x',
            'steps' => ['a' => ['type' => 'automatic']],
        ]);
    }

    public function testMissingIdThrows(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        WorkflowDefinition::fromArray([
            'startStep' => 'a',
            'steps' => ['a' => ['type' => 'automatic']],
        ]);
    }

    public function testNameDefaultsToId(): void
    {
        $def = WorkflowDefinition::fromArray([
            'id' => 'x',
            'startStep' => 'a',
            'steps' => ['a' => ['type' => 'automatic']],
        ]);

        self::assertSame('x', $def->name);
    }
}
