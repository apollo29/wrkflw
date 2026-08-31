<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Instance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Instance\ContextKeys;

/**
 * Der reservierte Namensraum der Engine.
 */
#[CoversClass(ContextKeys::class)]
final class ContextKeysTest extends TestCase
{
    public function testOnlyTheDoubleUnderscorePrefixCounts(): void
    {
        self::assertTrue(ContextKeys::isInternal('__parent'));
        self::assertTrue(ContextKeys::isInternal('__appliedEventIds'));
        // Der blosse Prefix ist selbst schon reserviert.
        self::assertTrue(ContextKeys::isInternal('__'));

        self::assertFalse(ContextKeys::isInternal('_single'));
        self::assertFalse(ContextKeys::isInternal('parent__x'));
        self::assertFalse(ContextKeys::isInternal('email'));
        self::assertFalse(ContextKeys::isInternal(''));
    }

    public function testStripInternalRemovesOnlyInternalKeys(): void
    {
        $stripped = ContextKeys::stripInternal([
            'email' => 'eltern@example.test',
            '__parent' => ['instanceId' => 'x'],
            '_single' => 'bleibt',
            '__appliedEventIds' => ['k1'],
        ]);

        self::assertSame(['email' => 'eltern@example.test', '_single' => 'bleibt'], $stripped);
    }

    public function testStripInternalPreservesValuesUnchanged(): void
    {
        $nested = ['a' => ['b' => 1], 'n' => null, 'f' => 1.5, 'b' => false];

        self::assertSame($nested, ContextKeys::stripInternal($nested));
        self::assertSame([], ContextKeys::stripInternal([]));
    }
}
