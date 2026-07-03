<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Exception\WorkflowException;

/**
 * Registry aller verfuegbaren Aktionen. Die Host-App registriert hier ihre eigenen
 * Aktionen zusaetzlich zu den eingebauten (z. B. send_email).
 */
final class ActionRegistry
{
    /** @var array<string,ActionInterface> */
    private array $actions = [];

    public function register(string $key, ActionInterface $action): void
    {
        $this->actions[$key] = $action;
    }

    public function get(string $key): ActionInterface
    {
        return $this->actions[$key]
            ?? throw new WorkflowException("Keine Aktion fuer Schluessel '{$key}' registriert.");
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    /**
     * @return list<string> die registrierten Action-Schluessel
     */
    public function keys(): array
    {
        return array_keys($this->actions);
    }
}
