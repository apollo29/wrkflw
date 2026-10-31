<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

/** Wiederholung mit wachsendem Abstand (Q-01, ADR 5). */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 3,
        private int $baseDelaySeconds = 60,
    ) {
    }

    /**
     * Wartezeit bis zum nächsten Versuch, nachdem `$attempts` Versuche gescheitert sind,
     * oder null, wenn keiner mehr folgt.
     */
    public function delayAfterAttempt(int $attempts): ?int
    {
        if ($attempts >= $this->maxAttempts) {
            return null;
        }

        return $this->baseDelaySeconds * (2 ** max(0, $attempts - 1));
    }
}
