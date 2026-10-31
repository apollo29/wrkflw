<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Clock\ClockInterface;
use WorkflowEngine\Instance\ContextKeys;

/**
 * Ersetzt Platzhalter wie `{{antrag_id}}` durch Werte aus dem Kontext. Gemeinsam für
 * alle Aktionen (Mail, Daten schreiben …).
 *
 * - Ein exakter Schlüssel hat Vorrang, sonst gilt ein Punkt als Pfad: `{{subWorkflow.status}}`.
 * - Eingebaute Zeitwerte, die der Kontext nicht überschreiben kann:
 *   `{{now}}` Datum (ISO), `{{now.datetime}}` Zeitpunkt (ISO 8601), `{{now.year}}` Jahr.
 * - Fehlende, interne und nicht skalare Werte ergeben einen leeren Text.
 */
final readonly class PlaceholderRenderer
{
    private const string PATTERN = '/\{\{\s*([\w.]+)\s*\}\}/u';

    public function __construct(private ClockInterface $clock = new SystemClock())
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context): string
    {
        return preg_replace_callback(
            self::PATTERN,
            fn (array $m): string => self::stringify($this->lookup($m[1], $context)),
            $template,
        ) ?? $template;
    }

    /**
     * Der Wert eines einzelnen Platzhalters, unverändert im Typ (für Daten schreiben).
     *
     * @param array<string, mixed> $context
     */
    public function lookup(string $name, array $context): mixed
    {
        $builtIn = $this->builtIn($name);
        if ($builtIn !== null) {
            return $builtIn;
        }
        if (ContextKeys::isInternal($name)) {
            return null;
        }
        if (array_key_exists($name, $context)) {
            return $context[$name];
        }

        $value = $context;
        foreach (explode('.', $name) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Der Name, wenn der Text genau aus einem Platzhalter besteht, sonst null. */
    public static function singlePlaceholder(string $text): ?string
    {
        return preg_match('/^\s*\{\{\s*([\w.]+)\s*\}\}\s*$/u', $text, $m) === 1 ? $m[1] : null;
    }

    private function builtIn(string $name): ?string
    {
        $now = $this->clock->now();

        return match ($name) {
            'now', 'now.date' => $now->format('Y-m-d'),
            'now.datetime' => $now->format(\DATE_ATOM),
            'now.year' => $now->format('Y'),
            default => null,
        };
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
