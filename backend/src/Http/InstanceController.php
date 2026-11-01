<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\ContextKeys;
use WorkflowEngine\Instance\HistoryEntry;
use WorkflowEngine\Instance\InstanceFilter;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\InstanceSummary;
use WorkflowEngine\Instance\WorkflowInstance;

/** Durchläufe starten, anzeigen, weiterführen, abbrechen und auflisten. */
final readonly class InstanceController
{
    public const int MAX_LIMIT = 200;

    public function __construct(private ApiServices $s)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function start(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = JsonIo::body($request);
        $instance = $this->s->engine->start(
            JsonIo::arg($args, 'def'),
            JsonIo::assoc($body['context'] ?? null),
            JsonIo::string($body, 'subjectType'),
            JsonIo::string($body, 'subjectId'),
        );

        return JsonIo::json($response, self::brief($instance), 201);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $status = JsonIo::query($request, 'status');
        $statusEnum = $status === null ? null : (InstanceStatus::tryFrom($status)
            ?? throw RequestValidationException::field('status', "Unbekannter Status '{$status}'."));
        $definition = JsonIo::query($request, 'definition');
        $filter = new InstanceFilter(
            status: $statusEnum,
            definitionId: $definition,
            subjectType: JsonIo::query($request, 'subjectType'),
            subjectId: JsonIo::query($request, 'subjectId'),
            limit: JsonIo::queryInt($request, 'limit', 50, 1, self::MAX_LIMIT),
            offset: JsonIo::queryInt($request, 'offset', 0, 0, PHP_INT_MAX),
        );

        return JsonIo::json($response, [
            'instances' => array_map(self::summary(...), $this->s->instances->listInstances($filter)),
            'counts' => $this->s->instances->countByStatus($definition),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->find($args);

        return JsonIo::json($response, self::brief($instance) + [
            'definitionId' => $instance->definitionId,
            'definitionVersion' => $instance->definitionVersion,
            'subjectType' => $instance->subjectType,
            'subjectId' => $instance->subjectId,
            'wakeAt' => JsonIo::time($instance->wakeAt),
            // Ohne engine-interne Schlüssel (ADR 6).
            'context' => (object) ContextKeys::stripInternal($instance->context),
            'lastError' => $instance->lastError,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function currentStep(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->find($args);
        $step = $this->s->workflows->findDefinition($instance->definitionId, $instance->definitionVersion)->step($instance->currentStep);

        $events = [];
        foreach ($step->transitions as $transition) {
            if ($transition->event !== null && !isset($events[$transition->event])) {
                $events[$transition->event] = ['event' => $transition->event, 'label' => $transition->label ?? $transition->event];
            }
        }

        return JsonIo::json($response, [
            'instanceId' => $instance->id,
            'status' => $instance->status->value,
            'step' => $step->name,
            'type' => $step->type->value,
            'interactive' => $step->isInteractive(),
            'finished' => $instance->isFinished(),
            'ui' => (object) $step->ui,
            'events' => array_values($events),
            // Nur anbieten, was die Engine danach nicht mit 409 abweist.
            'canGoBack' => $this->s->engine->canGoBack($instance),
            'context' => (object) ContextKeys::stripInternal($instance->context),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function postEvent(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = JsonIo::body($request);
        $event = JsonIo::required($body, 'event');
        $key = $request->getHeaderLine('Idempotency-Key');
        $instance = $this->s->engine->handleEvent(
            JsonIo::arg($args, 'id'),
            $event,
            JsonIo::assoc($body['payload'] ?? null),
            $key !== '' ? $key : null,
        );

        return JsonIo::json($response, self::brief($instance));
    }

    /**
     * @param array<string, string> $args
     */
    public function back(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonIo::json($response, self::brief($this->s->engine->goBack(JsonIo::arg($args, 'id'))));
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return JsonIo::json($response, self::brief($this->s->engine->cancel(JsonIo::arg($args, 'id'))));
    }

    /**
     * @param array<string, string> $args
     */
    public function history(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $instance = $this->find($args);

        return JsonIo::json($response, [
            'instanceId' => $instance->id,
            'history' => array_map(static fn (HistoryEntry $e): array => [
                'kind' => $e->kind->value,
                'step' => $e->step,
                'detail' => (object) $e->detail,
                'at' => JsonIo::time($e->at),
            ], $this->s->workflows->findHistory($instance->id)),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    private function find(array $args): WorkflowInstance
    {
        $id = JsonIo::arg($args, 'id');

        return $this->s->workflows->findInstance($id) ?? throw NotFoundException::instance($id);
    }

    /**
     * @return array{id: string, status: string, currentStep: string}
     */
    private static function brief(WorkflowInstance $instance): array
    {
        return ['id' => $instance->id, 'status' => $instance->status->value, 'currentStep' => $instance->currentStep];
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(InstanceSummary $i): array
    {
        return [
            'id' => $i->id,
            'definitionId' => $i->definitionId,
            'definitionVersion' => $i->definitionVersion,
            'definitionName' => $i->definitionName,
            'currentStep' => $i->currentStep,
            'status' => $i->status->value,
            'subjectType' => $i->subjectType,
            'subjectId' => $i->subjectId,
            'wakeAt' => JsonIo::time($i->wakeAt),
            'lastError' => $i->lastError,
            'createdAt' => JsonIo::time($i->createdAt),
            'updatedAt' => JsonIo::time($i->updatedAt),
        ];
    }
}
