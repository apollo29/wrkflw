<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Version;

final class VersionTest extends TestCase
{
    public function testEngineVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', Version::ENGINE);
    }

    public function testApiVersionFollowsEngineMajor(): void
    {
        self::assertSame(3, Version::major());
        self::assertSame(Version::major(), Version::API);
    }
}
