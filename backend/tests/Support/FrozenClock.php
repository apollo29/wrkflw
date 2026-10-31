<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use Psr\Clock\ClockInterface;

/** Eine Uhr, die nur weiterläuft, wenn der Test sie stellt. */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-10-31 09:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('Europe/Zurich'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
