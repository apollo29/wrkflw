<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Findet doppelte Schlüssel in einem JSON-Text.
 *
 * `json_decode` übernimmt bei doppelten Schlüsseln stillschweigend den letzten Wert.
 * Bei Schrittnamen hiesse das: ein Schritt verschwindet beim Speichern ohne Warnung
 * (9.14 «Doppelte Namen»). Der Scanner liest nur die Struktur, keine Werte, und setzt
 * gültiges JSON voraus; ungültiges meldet danach `json_decode`.
 */
final class JsonDuplicateKeys
{
    private function __construct()
    {
    }

    /**
     * @return list<string> Pfade der doppelten Schlüssel, etwa `steps.pruefen`
     */
    public static function find(string $json): array
    {
        /** @var list<array{object: bool, keys: array<string, true>, expectKey: bool, path: string, key: ?string}> $stack */
        $stack = [];
        $duplicates = [];
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($char === '"') {
                [$string, $i] = self::readString($json, $i);
                $top = array_key_last($stack);
                if ($top !== null && $stack[$top]['object'] && $stack[$top]['expectKey']) {
                    $path = ltrim($stack[$top]['path'] . '.' . $string, '.');
                    if (isset($stack[$top]['keys'][$string])) {
                        $duplicates[] = $path;
                    }
                    $stack[$top]['keys'][$string] = true;
                    $stack[$top]['expectKey'] = false;
                    $stack[$top]['key'] = $string;
                }
                continue;
            }

            if ($char === '{' || $char === '[') {
                $top = array_key_last($stack);
                $path = '';
                if ($top !== null) {
                    $segment = $stack[$top]['object'] ? (string) $stack[$top]['key'] : '[]';
                    $path = ltrim($stack[$top]['path'] . '.' . $segment, '.');
                }
                $stack[] = ['object' => $char === '{', 'keys' => [], 'expectKey' => $char === '{', 'path' => $path, 'key' => null];
                continue;
            }

            if ($char === '}' || $char === ']') {
                array_pop($stack);
                continue;
            }

            if ($char === ',') {
                $top = array_key_last($stack);
                if ($top !== null && $stack[$top]['object']) {
                    $stack[$top]['expectKey'] = true;
                }
            }
        }

        return array_values(array_unique($duplicates));
    }

    /**
     * @return array{string, int} der dekodierte Text und der Index des schliessenden Anführungszeichens
     */
    private static function readString(string $json, int $start): array
    {
        $length = strlen($json);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($json[$i] === '\\') {
                $i++;
                continue;
            }
            if ($json[$i] === '"') {
                $decoded = json_decode(substr($json, $start, $i - $start + 1));

                return [is_string($decoded) ? $decoded : substr($json, $start + 1, $i - $start - 1), $i];
            }
        }

        return [substr($json, $start + 1), $length];
    }
}
