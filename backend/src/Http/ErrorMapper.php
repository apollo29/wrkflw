<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use WorkflowEngine\Exception\ExpressionException;
use WorkflowEngine\Exception\InvalidDefinitionException;
use WorkflowEngine\Exception\InvalidStateException;
use WorkflowEngine\Exception\MissingInputException;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Rest\DataSourceValidationException;
use WorkflowEngine\Rest\RestSpecException;

/**
 * Übersetzt Ausnahmen an einer Stelle in das Fehlerformat `{error: {code, message,
 * details?}}` (ADR 11). Bei 500 geht kein Text hinaus: Meldungen unbekannter Fehler
 * könnten Daten oder Zugangsdaten enthalten.
 */
final class ErrorMapper
{
    private function __construct()
    {
    }

    public static function respond(\Throwable $e, ResponseInterface $response): ResponseInterface
    {
        return match (true) {
            $e instanceof HttpNotFoundException => JsonIo::error($response, 404, 'not_found', 'Diese Adresse gibt es nicht.'),
            $e instanceof HttpMethodNotAllowedException => JsonIo::error($response, 405, 'method_not_allowed', 'Diese Methode ist hier nicht erlaubt.')
                ->withHeader('Allow', implode(', ', $e->getAllowedMethods())),
            $e instanceof HttpException => JsonIo::error($response, $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 400, 'http_error', $e->getMessage()),
            $e instanceof NotFoundException => JsonIo::error($response, 404, 'not_found', $e->getMessage()),
            $e instanceof MissingInputException => JsonIo::error($response, 422, 'missing_input', $e->getMessage(), ['missing' => $e->missing]),
            $e instanceof InvalidDefinitionException => JsonIo::error($response, 422, 'invalid_definition', $e->getMessage(), [
                'errors' => $e->report()?->toArray() ?? array_map(static fn (string $m): array => ['code' => 'invalid', 'message' => $m, 'step' => null, 'transition' => null], $e->errors()),
            ]),
            $e instanceof RequestValidationException => JsonIo::error($response, 422, 'validation_error', $e->getMessage(), $e->errors === [] ? null : ['fields' => $e->errors]),
            $e instanceof DataSourceValidationException => JsonIo::error($response, 422, 'validation_error', 'Bitte die markierten Angaben prüfen.', ['fields' => $e->errors]),
            $e instanceof RestSpecException, $e instanceof DataModelException, $e instanceof ExpressionException => JsonIo::error($response, 422, 'validation_error', $e->getMessage()),
            $e instanceof InvalidStateException => JsonIo::error($response, 409, 'invalid_state', $e->getMessage()),
            $e instanceof ConflictException => JsonIo::error($response, 409, $e->errorCode, $e->getMessage()),
            default => JsonIo::error($response, 500, 'internal_error', 'Interner Serverfehler.'),
        };
    }
}
