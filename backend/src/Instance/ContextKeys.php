<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/**
 * Der reservierte Namensraum der Engine im Instanz-Kontext.
 *
 * Schluessel mit dem Prefix "__" gehoeren der Engine selbst: die Idempotenz-Liste
 * (siehe ADR 0005) und die Marker verknuepfter Workflows
 * ({@see \WorkflowEngine\Contracts\WorkflowStarterInterface}). Sie steuern das
 * Verhalten der Engine und duerfen deshalb niemals aus einem Event-Payload
 * stammen — siehe ADR 0006.
 *
 * Diese Klasse ist die einzige Stelle, an der "intern" definiert ist. Vorher
 * stand dieselbe Schleife zweimal im Code (Engine und SubWorkflowAction), und
 * zwei Kopien einer Sicherheitsregel laufen frueher oder spaeter auseinander.
 */
final class ContextKeys
{
    public const INTERNAL_PREFIX = '__';

    private function __construct()
    {
    }

    public static function isInternal(string $key): bool
    {
        return str_starts_with($key, self::INTERNAL_PREFIX);
    }

    /**
     * Der Kontext ohne die engine-internen Schluessel.
     *
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>
     */
    public static function stripInternal(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            if (!self::isInternal((string) $key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
