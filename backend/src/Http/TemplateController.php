<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\TemplateRepositoryInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;

/**
 * Verwaltung wiederverwendbarer Templates (Liste, lesen, anlegen/aktualisieren,
 * loeschen, Verwendungs-Anzeige). Liefert ausschliesslich JSON.
 */
final class TemplateController
{
    public function __construct(
        private readonly TemplateRepositoryInterface $templates,
        private readonly WorkflowRepositoryInterface $workflows,
    ) {
    }

    /**
     * GET /templates — optional per ?type=email|page gefiltert.
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $typeParam = $params['type'] ?? null;
        $type = is_string($typeParam) && $typeParam !== '' ? $this->normalizeType($typeParam) : null;

        return $this->json($response, ['templates' => $this->templates->listTemplates($type)]);
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
        $type = $this->normalizeType($this->stringField($body, 'type', 'email'));

        $this->templates->saveTemplate($id, $name, $subject, $content, $type);

        return $this->json($response, ['id' => $id], 201);
    }

    /**
     * DELETE /templates/{id}
     *
     * @param array<string,string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? '';
        $this->templates->deleteTemplate($id);

        return $this->json($response, ['id' => $id, 'deleted' => true]);
    }

    /**
     * GET /templates/{id}/usage — welche Workflow-Schritte referenzieren dieses Template.
     *
     * @param array<string,string> $args
     */
    public function usage(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? '';

        return $this->json($response, [
            'templateId' => $id,
            'usage' => $this->workflows->findTemplateUsage($id),
        ]);
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

    /** Erlaubte Template-Typen; alles andere faellt auf 'email' zurueck. */
    private function normalizeType(string $type): string
    {
        return $type === 'page' ? 'page' : 'email';
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
