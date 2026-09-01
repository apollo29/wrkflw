<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\InvalidDefinitionException;

/**
 * Verwaltung von Workflow-Definitionen (fuer den Editor): auflisten, lesen und
 * neue Versionen anlegen (mit Validierung). Liefert ausschliesslich JSON.
 */
final class DefinitionController
{
    public function __construct(
        private readonly WorkflowRepositoryInterface $repo,
        private readonly DefinitionValidator $validator,
    ) {
    }

    /**
     * GET /workflows — alle Definitionen (Kurzuebersicht).
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, ['definitions' => $this->repo->listDefinitions()]);
    }

    /**
     * GET /workflows/{def} — die aktive Definition als JSON-Objekt.
     *
     * @param array<string,string> $args
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['def'] ?? '';
        $json = $this->repo->findDefinitionJson($id);
        if ($json === null) {
            return $this->error($response, 'not_found', 'Definition nicht gefunden.', 404);
        }

        $definition = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $this->json($response, ['id' => $id, 'definition' => $definition]);
    }

    /**
     * DELETE /workflows/{def}/versions/{version} — eine alte Version entfernen.
     *
     * Bewusst KEIN 404/403-Unterschied: ob eine Version nicht existiert, die
     * neueste ist oder noch Durchlaeufe hat, laeuft fuer den Aufrufer auf
     * dasselbe hinaus — sie bleibt stehen. Ein 409 mit einem Satz dazu ist
     * ehrlicher als drei Codes, die alle «nein» heissen.
     *
     * @param array<string,string> $args
     */
    public function deleteVersion(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $id = (string) ($args['def'] ?? '');
        $version = (int) ($args['version'] ?? 0);

        if (!$this->repo->deleteDefinitionVersion($id, $version)) {
            $response->getBody()->write((string) json_encode([
                'error' => 'not_deletable',
                'message' => 'Diese Version bleibt bestehen: sie ist die aktuelle, '
                    . 'es hängen noch Durchläufe daran, oder es gibt sie nicht.',
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }

        return $response->withStatus(204);
    }

    /**
     * POST /workflows/{def} — neue Version anlegen (validiert, wird aktiv).
     *
     * @param array<string,string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['def'] ?? '';
        $body = $this->assoc($request->getParsedBody());

        $rawDefinition = $body['definition'] ?? null;
        if (!is_array($rawDefinition)) {
            return $this->error($response, 'validation_error', "Feld 'definition' (Objekt) ist erforderlich.", 422);
        }

        $name = $body['name'] ?? null;
        $name = is_string($name) && $name !== '' ? $name : $id;

        $status = $body['status'] ?? 'active';
        $status = in_array($status, ['active', 'inactive', 'draft'], true) ? $status : 'active';

        // id/name in die Definition einsetzen, dann strukturell + semantisch pruefen.
        $definition = $this->assoc($rawDefinition);
        $definition['id'] = $id;
        $definition['name'] ??= $name;

        try {
            $parsed = WorkflowDefinition::fromArray($definition);
            $this->validator->validate($parsed);
        } catch (InvalidDefinitionException $e) {
            return $this->error($response, 'invalid_definition', $e->getMessage(), 400);
        }

        $version = $this->repo->saveDefinition(
            $id,
            $name,
            json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
        );

        return $this->json($response, [
            'id' => $id,
            'version' => $version,
            'active' => $status === 'active',
            'status' => $status,
        ], 201);
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
