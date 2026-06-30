<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Test-Double: sammelt Log-Aufrufe im Speicher.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level:mixed,message:string,context:array<mixed>}> */
    private array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level:mixed,message:string,context:array<mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function hasMessage(string $message): bool
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return true;
            }
        }

        return false;
    }
}
