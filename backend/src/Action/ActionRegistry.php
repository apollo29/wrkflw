<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Exception\NotFoundException;

/** Verzeichnis der Aktionen nach Schlüssel (`send_email`, `start_workflow` …). */
final class ActionRegistry
{
    /** @var array<string, ActionInterface> */
    private array $actions = [];

    public function register(string $key, ActionInterface $action): void
    {
        $this->actions[$key] = $action;
    }

    public function get(string $key): ActionInterface
    {
        return $this->actions[$key] ?? throw NotFoundException::action($key);
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->actions);
    }

    /**
     * Katalog für den Editor: lesbare Bezeichnung und Felder je Aktion (9.7, 9.14).
     *
     * @return list<array{key: string, label: string, fields: list<array{name: string, label: string, type: string}>}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->actions as $key => $action) {
            $out[] = [
                'key' => $key,
                'label' => $action instanceof ConfigurableActionInterface ? $action->label() : $key,
                'fields' => $action instanceof ConfigurableActionInterface ? $action->configSchema() : [],
            ];
        }

        return $out;
    }
}
