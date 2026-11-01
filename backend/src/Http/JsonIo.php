<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Lesen und Schreiben von JSON für alle Controller. */
final class JsonIo
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        return self::assoc($request->getParsedBody());
    }

    /**
     * @return array<string, mixed>
     */
    public static function assoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function required(array $data, string $key): string
    {
        $value = self::string($data, $key);
        if ($value === null || trim($value) === '') {
            throw RequestValidationException::field($key, "Das Feld '{$key}' ist erforderlich.");
        }

        return $value;
    }

    public static function query(ServerRequestInterface $request, string $key): ?string
    {
        $value = $request->getQueryParams()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function queryInt(ServerRequestInterface $request, string $key, int $default, int $min, int $max): int
    {
        $value = self::query($request, $key);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw RequestValidationException::field($key, "'{$key}' muss eine ganze Zahl sein.");
        }

        return max($min, min($max, (int) $value));
    }

    /**
     * @param array<string, string> $args
     */
    public static function arg(array $args, string $key): string
    {
        return $args[$key] ?? '';
    }

    /**
     * @param array<string, string> $args
     */
    public static function intArg(array $args, string $key): int
    {
        $value = $args[$key] ?? '';
        if (preg_match('/^\d{1,9}$/', $value) !== 1) {
            throw RequestValidationException::field($key, "'{$key}' muss eine positive ganze Zahl sein.");
        }

        return (int) $value;
    }

    public static function json(ResponseInterface $response, mixed $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public static function empty(ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(204);
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public static function error(ResponseInterface $response, int $status, string $code, string $message, ?array $details = null): ResponseInterface
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return self::json($response, ['error' => $error], $status);
    }

    public static function time(?\DateTimeImmutable $time): ?string
    {
        return $time?->format(\DateTimeInterface::ATOM);
    }

    public static function access(ServerRequestInterface $request): Access
    {
        $access = $request->getAttribute(Access::ATTRIBUTE);

        return $access instanceof Access ? $access : throw new \LogicException('Kein Zugriff ermittelt; AccessMiddleware fehlt.');
    }
}
