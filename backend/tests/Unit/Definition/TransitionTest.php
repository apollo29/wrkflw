<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\Transition;
use WorkflowEngine\Exception\InvalidDefinitionException;

#[CoversClass(Transition::class)]
final class TransitionTest extends TestCase
{
    public function testFromArrayReadsAllFields(): void
    {
        $t = Transition::fromArray(['to' => 'next', 'when' => "context['x'] > 1", 'event' => 'submit']);

        self::assertSame('next', $t->to);
        self::assertSame("context['x'] > 1", $t->when);
        self::assertSame('submit', $t->event);
    }

    public function testDefaultsWhenToTrueAndEventToNull(): void
    {
        $t = Transition::fromArray(['to' => 'next']);

        self::assertSame('true', $t->when);
        self::assertNull($t->event);
    }

    public function testMissingToThrows(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        Transition::fromArray(['when' => 'true']);
    }

    public function testEmptyToThrows(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        Transition::fromArray(['to' => '']);
    }
}
