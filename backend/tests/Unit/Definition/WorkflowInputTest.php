<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Definition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Definition\WorkflowInput;

final class WorkflowInputTest extends TestCase
{
    public function testNamelessInputIsIgnored(): void
    {
        self::assertNull(WorkflowInput::fromArray(['name' => '  ', 'required' => true]));
    }

    public function testLabelFallsBackToNameAndRequiredDefaultsToFalse(): void
    {
        $input = WorkflowInput::fromArray(['name' => 'antrag_id']);

        self::assertNotNull($input);
        self::assertSame('antrag_id', $input->label);
        self::assertFalse($input->required);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function contexts(): iterable
    {
        yield 'fehlt' => [[], true];
        yield 'null' => [['a' => null], true];
        yield 'leer' => [['a' => '  '], true];
        yield 'null als Zahl' => [['a' => 0], false];
        yield 'falsch' => [['a' => false], false];
        yield 'Text' => [['a' => 'x'], false];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[DataProvider('contexts')]
    public function testRequiredValueSemantics(array $context, bool $missing): void
    {
        $input = WorkflowInput::fromArray(['name' => 'a', 'required' => true]);

        self::assertNotNull($input);
        self::assertSame($missing, $input->isMissingIn($context));
    }

    public function testOptionalInputIsNeverMissing(): void
    {
        $input = WorkflowInput::fromArray(['name' => 'a']);

        self::assertNotNull($input);
        self::assertFalse($input->isMissingIn([]));
    }
}
