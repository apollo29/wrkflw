<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Contracts\WorkflowRepositoryInterface;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Exception\InvalidDefinitionException;
use WorkflowEngine\Exception\WorkflowException;

/**
 * HTTP-Endpunkte der Workflow-Engine. Liefert ausschliesslich JSON.
 * Wird von der ApiFactory an die Slim-Routen gebunden.
 */
final class WorkflowController
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly WorkflowRepositoryInterface $repo,
    ) {
    }

    /**
     * POST /workflows/{def}/instances
     *
     * @param array<string,string> $args
     */
    public function start(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $this->body($request);
        $definition = $args['def'] ?? '';

        try {
            $instance = $this->engine->start(
                $definition,
                $this->assoc($body['context'] ?? null),
                $this->nullableString($body, 'subjectType'),
                $this->nullableString($body, 'subjectId'),
            );
        } catch (InvalidDefinitionException $e) {
            return $this->error($response, 'invalid_definition', $e->getMessage(), 400);
        } catch (WorkflowException $e) {
            return $this->error($response, 'not_found', $e->getMessage(), 404);
        }

        return $this->json($response, [
            'id' => $instance->id,
            'status' => $instance->status,
            'currentStep' => $instance->currentStep,
        ], 201);
    }

    /**
     * GET /instances/{id}
     *
     * @param array<string,string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->repo->findInstance($args['id'] ?? '');
        if ($instance === null) {
            return $this->error($response, 'not_found', 'Instanz nicht gefunden.', 404);
        }

        return $this->json($response, [
            'id' => $instance->id,
            'status' => $instance->status,
            'currentStep' => $instance->currentStep,
            'context' => $instance->context,
            'lastError' => $instance->lastError,
        ]);
    }

    /**
     * GET /instances/{id}/current-step — aktueller Schritt inkl. UI-Metadaten fuers Frontend.
     *
     * @param array<string,string> $args
     */
    public function currentStep(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->repo->findInstance($args['id'] ?? '');
        if ($instance === null) {
            return $this->error($response, 'not_found', 'Instanz nicht gefunden.', 404);
        }

        $def = $this->repo->findDefinition($instance->definitionId, $instance->definitionVersion);
        $step = $def->step($instance->currentStep);

        $events = [];
        foreach ($step->transitions as $t) {
            if ($t->event !== null) {
                $events[$t->event] = true;
            }
        }

        return $this->json($response, [
            'instanceId' => $instance->id,
            'status' => $instance->status,
            'step' => $step->name,
            'type' => $step->type,
            'interactive' => $step->isInteractive(),
            'finished' => $instance->isFinished(),
            'ui' => $step->ui,
            'events' => array_keys($events),
            'context' => $instance->context,
        ]);
    }

    /**
     * POST /instances/{id}/events
     *
     * @param array<string,string> $args
     */
    public function postEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $this->body($request);
        $event = $body['event'] ?? null;
        if (!is_string($event) || $event === '') {
            return $this->error($response, 'validation_error', "Feld 'event' ist erforderlich.", 422);
        }

        $id = $args['id'] ?? '';
        if ($this->repo->findInstance($id) === null) {
            return $this->error($response, 'not_found', 'Instanz nicht gefunden.', 404);
        }

        $idempotencyKey = $request->getHeaderLine('Idempotency-Key');

        try {
            $instance = $this->engine->handleEvent(
                $id,
                $event,
                $this->assoc($body['payload'] ?? null),
                $idempotencyKey !== '' ? $idempotencyKey : null,
            );
        } catch (WorkflowException $e) {
            return $this->error($response, 'conflict', $e->getMessage(), 409);
        }

        return $this->json($response, [
            'id' => $instance->id,
            'status' => $instance->status,
            'currentStep' => $instance->currentStep,
        ]);
    }

    /**
     * GET /instances/{id}/history
     *
     * @param array<string,string> $args
     */
    public function history(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? '';
        if ($this->repo->findInstance($id) === null) {
            return $this->error($response, 'not_found', 'Instanz nicht gefunden.', 404);
        }

        return $this->json($response, [
            'instanceId' => $id,
            'history' => $this->repo->findHistory($id),
        ]);
    }

    // ---------------------------------------------------------------- intern

    /**
     * @return array<string,mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        return $this->assoc($request->getParsedBody());
    }

    /**
     * Normalisiert einen beliebigen Wert zu einem assoziativen Array mit String-Keys.
     *
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
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
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
