<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Das Ergebnis einer Aktion: Werte für den Kontext und optional ein Kind-Durchlauf,
 * auf dessen Ende der Ablauf warten soll.
 */
final readonly class ActionResult
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        public array $context,
        public ?string $awaitChildId,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function of(array $context = []): self
    {
        return new self($context, null);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function awaitChild(string $childId, array $context = []): self
    {
        return new self($context, $childId);
    }
}
