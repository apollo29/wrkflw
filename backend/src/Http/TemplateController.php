<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\TemplateRepositoryInterface;

/**
 * Verwaltung wiederverwendbarer Templates (Liste, lesen, anlegen/aktualisieren).
 * Liefert ausschliesslich JSON.
 */
final class TemplateController
{
    public function __construct(private readonly TemplateRepositoryInterface $templates)
    {
    }

    /**
     * GET /templates
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['templates' => $this->templates->listTemplates()]);
    }

    /**
     * GET /templates/{id}
     *
     * @param array<string,string> $args
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $template = $this->templates->findTemplate($args['id'] ?? '');
        if ($template === null) {
            return $this->error($response, 'not_found', 'Template nicht gefunden.', 404);
        }

        return $this->json($response, $template);
    }

    /**
     * POST /templates/{id}
     *
     * @param array<string,string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? '';
        if ($id === '') {
            return $this->error($response, 'validation_error', "Feld 'id' ist erforderlich.", 422);
        }

        $body = $this->assoc($request->getParsedBody());
        $name = $this->stringField($body, 'name', $id);
        $subject = $this->stringField($body, 'subject', '');
        $content = $this->stringField($body, 'body', '');

        $this->templates->saveTemplate($id, $name, $subject, $content);

        return $this->json($response, ['id' => $id], 201);
    }

    // ---------------------------------------------------------------- intern

    /**
     * @return array<string,mixed>
     */
    private function assoc(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $val) {
            $out[(string) $key] = $val;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function stringField(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(ResponseInterface $response, array $payload, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    private function error(ResponseInterface $response, string $code, string $message, int $status): ResponseInterface
    {
        return $this->json($response, ['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
