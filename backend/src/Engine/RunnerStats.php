<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

/** Ergebnis eines Hintergrundlaufs. */
final readonly class RunnerStats
{
    public function __construct(
        public int $woken = 0,
        public int $started = 0,
        public int $locked = 0,
        public int $errors = 0,
    ) {
    }

    /**
     * @return array{woken: int, started: int, locked: int, errors: int}
     */
    public function toArray(): array
    {
        return ['woken' => $this->woken, 'started' => $this->started, 'locked' => $this->locked, 'errors' => $this->errors];
    }
}
