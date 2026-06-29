<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use WorkflowEngine\Contracts\ExpressionEvaluatorInterface;
use WorkflowEngine\Exception\ExpressionException;

/**
 * Default-Evaluator auf Basis der Symfony ExpressionLanguage (sandboxed).
 *
 * Sicherheit:
 *  - KEINE eingebauten Funktionen (auch nicht das standardmaessige constant()) —
 *    die zugrunde liegende Sprache wird ohne Default-Funktionen initialisiert.
 *  - Nur ausdruecklich freigegebene Funktionen sind aufrufbar: die eingebauten
 *    Zeitfunktionen days()/hours()/minutes() sowie per Konstruktor uebergebene.
 *  - Beliebige PHP-Funktionen sind nicht erreichbar (fuehren zu ExpressionException).
 *
 * Beispiele fuer "when"-Ausdruecke in Transitionen:
 *   "context['amount'] > 100"
 *   "context['status'] == 'approved'"
 *   "context['dueDate'] < now"
 *   "context['vip'] and context['amount'] > 1000"
 *   "context['lastSeen'] < now - days(30)"
 */
final class SymfonyExpressionEvaluator implements ExpressionEvaluatorInterface
{
    private readonly ExpressionLanguage $el;

    /**
     * @param list<ExpressionFunction> $functions zusaetzlich freigegebene Funktionen der Host-App
     */
    public function __construct(array $functions = [])
    {
        // Sprache ohne Default-Funktionen (Sandbox): registerFunctions() bleibt leer.
        $this->el = new class () extends ExpressionLanguage {
            protected function registerFunctions(): void
            {
                // bewusst leer — keine eingebauten Funktionen freigeben.
            }
        };

        $this->registerDuration('days', 86_400);
        $this->registerDuration('hours', 3_600);
        $this->registerDuration('minutes', 60);

        foreach ($functions as $function) {
            $this->el->addFunction($function);
        }
    }

    public function evaluate(string $expression, array $scope): bool
    {
        return (bool) $this->evaluateValue($expression, $scope);
    }

    public function evaluateValue(string $expression, array $scope): mixed
    {
        if (!array_key_exists('now', $scope)) {
            $scope['now'] = time();
        }

        // Fehlende Kontext-Keys/Variablen tolerieren: zu null auswerten statt Warnung.
        set_error_handler(static function (int $errno, string $message): bool {
            return str_contains($message, 'Undefined array key')
                || str_contains($message, 'Undefined variable');
        }, E_WARNING | E_NOTICE);

        try {
            return $this->el->evaluate($expression, $scope);
        } catch (SyntaxError $e) {
            throw new ExpressionException("Ungueltiger Ausdruck: {$e->getMessage()}", 0, $e);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Registriert eine freigegebene Zeitfunktion, die einen Faktor in Sekunden liefert
     * (z. B. days(3) => 259200), nutzbar in Kombination mit "now".
     */
    private function registerDuration(string $name, int $secondsPerUnit): void
    {
        $this->el->register(
            $name,
            static fn (string $n): string => "({$n} * {$secondsPerUnit})",
            static function (array $values, mixed $n) use ($name, $secondsPerUnit): int {
                if (!is_numeric($n)) {
                    throw new ExpressionException("Funktion '{$name}()' erwartet eine Zahl.");
                }

                return (int) $n * $secondsPerUnit;
            },
        );
    }
}
