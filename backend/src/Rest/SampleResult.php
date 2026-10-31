<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/**
 * Ein Beispieldatensatz für «Beispiel abrufen» (F-139): der Baum der Antwort und alle Pfade
 * mit vermutetem Typ, damit der Admin Felder per Klick übernehmen kann. Wird nie
 * gespeichert oder protokolliert, weil er Personendaten enthalten kann.
 */
final readonly class SampleResult
{
    /**
     * @param list<array{path: string, type: string, sample: string|int|float|bool|null}> $paths
     */
    public function __construct(
        public int $status,
        public mixed $tree,
        public array $paths,
    ) {
    }

    public static function fromTree(int $status, mixed $tree): self
    {
        $paths = [];
        self::walk($tree, '', $paths);

        return new self($status, $tree, $paths);
    }

    /**
     * @param list<array{path: string, type: string, sample: string|int|float|bool|null}> $paths
     */
    private static function walk(mixed $node, string $prefix, array &$paths): void
    {
        if (!is_array($node) || array_is_list($node)) {
            if ($prefix !== '' && (is_scalar($node) || $node === null)) {
                $paths[] = ['path' => $prefix, 'type' => self::type($node), 'sample' => $node];
            }

            return;
        }
        foreach ($node as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                continue;
            }
            self::walk($value, $prefix === '' ? $key : $prefix . '.' . $key, $paths);
        }
    }

    private static function type(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value), is_float($value) => 'number',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 => 'date',
            is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1 => 'datetime',
            default => 'text',
        };
    }
}
