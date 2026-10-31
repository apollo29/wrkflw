<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Clock\ClockInterface;

/** Die echte Uhr. Tests ersetzen sie, damit Zeit deterministisch wird. */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
