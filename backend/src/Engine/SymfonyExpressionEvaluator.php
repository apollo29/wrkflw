<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Psr\Clock\ClockInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use WorkflowEngine\Contracts\ExpressionCheckerInterface;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Contracts\ExpressionFunctionProviderInterface;
use WorkflowEngine\Exception\ExpressionException;

/**
 * Standard-Auswertung von Bedingungen mit Symfony ExpressionLanguage in einer
 * abgeschotteten Umgebung (F-100):
 *
 * - keine eingebauten Funktionen (`constant`, `enum`, `min` … sind gesperrt),
 * - nur die Namen `context` und `now`,
 * - Zeitfunktionen `days()`, `hours()`, `minutes()` (liefern Sekunden),
 * - dazu nur, was die Host-App über einen Provider freigibt.
 *
 * Fehlende Kontext-Schlüssel ergeben `null` statt einer Warnung: ein Feld, das ein
 * Ablauf noch nicht gesetzt hat, soll eine Bedingung nicht sprengen.
 */
final class SymfonyExpressionEvaluator implements ExpressionEvaluatorInterface, ExpressionCheckerInterface
{
    private const array NAMES = ['context', 'now'];

    private readonly ExpressionLanguage $language;

    /**
     * @param iterable<ExpressionFunctionProviderInterface> $providers
     */
    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
        iterable $providers = [],
    ) {
        $this->language = new class () extends ExpressionLanguage {
            protected function registerFunctions(): void
            {
                // Bewusst leer: keine eingebauten Funktionen freigeben.
            }
        };

        $this->registerDuration('days', 86_400);
        $this->registerDuration('hours', 3_600);
        $this->registerDuration('minutes', 60);

        foreach ($providers as $provider) {
            foreach ($provider->functions() as $name => $function) {
                $this->language->register(
                    $name,
                    static fn (): string => throw new \LogicException('Ausdrücke werden nicht kompiliert.'),
                    static fn (array $values, mixed ...$args): mixed => $function(...$args),
                );
            }
        }
    }

    public function check(string $expression): void
    {
        try {
            $this->language->parse($expression, self::NAMES);
        } catch (SyntaxError $e) {
            throw new ExpressionException("Ungültiger Ausdruck: {$e->getMessage()}", 0, $e);
        }
    }

    public function evaluate(string $expression, array $scope): bool
    {
        return (bool) $this->evaluateValue($expression, $scope);
    }

    public function evaluateValue(string $expression, array $scope): mixed
    {
        $scope = array_intersect_key($scope, array_flip(self::NAMES));
        $scope['context'] ??= [];
        $scope['now'] ??= $this->clock->now()->getTimestamp();

        set_error_handler(static fn (int $errno, string $message): bool => str_contains($message, 'Undefined array key')
            || str_contains($message, 'Undefined variable')
            || str_contains($message, 'on null'), E_WARNING | E_NOTICE);

        try {
            return $this->language->evaluate($expression, $scope);
        } catch (ExpressionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ExpressionException("Ausdruck '{$expression}' lässt sich nicht auswerten: {$e->getMessage()}", 0, $e);
        } finally {
            restore_error_handler();
        }
    }

    private function registerDuration(string $name, int $secondsPerUnit): void
    {
        $this->language->register(
            $name,
            static fn (string $n): string => "({$n} * {$secondsPerUnit})",
            static function (array $values, mixed $n) use ($name, $secondsPerUnit): int {
                if (!is_numeric($n)) {
                    throw new ExpressionException("Funktion '{$name}()' erwartet eine Zahl.");
                }

                return (int) round((float) $n * $secondsPerUnit);
            },
        );
    }
}
