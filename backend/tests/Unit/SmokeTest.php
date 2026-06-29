<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Platzhalter, damit die Unit-Suite gruen laeuft, bevor Domaenenlogik existiert.
 * Wird in Phase 1 durch echte Tests ersetzt/ergaenzt.
 */
#[CoversNothing]
final class SmokeTest extends TestCase
{
    public function testAutoloadingResolvesDependencies(): void
    {
        self::assertTrue(class_exists(ExpressionLanguage::class));
    }
}
