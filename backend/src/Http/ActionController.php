<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ConfigurableActionInterface;

/**
 * Liefert den Katalog der registrierten Actions (fuer den Editor), inklusive
 * optionalem Config-Schema (wenn die Action ConfigurableActionInterface implementiert).
 */
final class ActionController
{
    public function __construct(private readonly ActionRegistry $actions)
    {
    }

    /**
     * GET /actions
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $catalog = [];
        foreach ($this->actions->keys() as $key) {
            $action = $this->actions->get($key);
            $catalog[] = [
                'key' => $key,
                'config' => $action instanceof ConfigurableActionInterface ? $action->configSchema() : [],
            ];
        }

        $response->getBody()->write(json_encode(['actions' => $catalog], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
