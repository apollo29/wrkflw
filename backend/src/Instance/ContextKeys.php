<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/**
 * Engine-interne Kontext-Schlüssel beginnen mit `__`. Sie lassen sich nie über ein
 * Ereignis setzen und verlassen die Engine nicht (F-101, ADR 6).
 */
final class ContextKeys
{
    public const string INTERNAL_PREFIX = '__';

    /** Verweis eines Kind-Ablaufs auf den wartenden Eltern-Ablauf. */
    public const string PARENT_LINK = '__parent';

    /** Der Eltern-Ablauf wartet auf diesen Kind-Durchlauf. */
    public const string AWAIT_WORKFLOW = '__awaitWorkflow';

    /** Verschachtelungstiefe verknüpfter Abläufe. */
    public const string SUB_DEPTH = '__subDepth';

    /** Bereits angewendete Ereignis-IDs (Idempotenz, Q-04). */
    public const string APPLIED_EVENTS = '__appliedEventIds';

    private function __construct()
    {
    }

    public static function isInternal(string $key): bool
    {
        return str_starts_with($key, self::INTERNAL_PREFIX);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public static function stripInternal(array $context): array
    {
        return array_filter($context, static fn (string $key): bool => !self::isInternal($key), ARRAY_FILTER_USE_KEY);
    }
}
