<?php

declare(strict_types=1);

namespace WorkflowEngine\Exception;

/** Eine Definition, Version, Instanz oder Vorlage existiert nicht. */
final class NotFoundException extends WorkflowException
{
    public static function definition(string $id, ?int $version = null): self
    {
        return new self($version === null
            ? "Workflow '{$id}' nicht gefunden."
            : "Workflow '{$id}' in Version {$version} nicht gefunden.");
    }

    public static function instance(string $id): self
    {
        return new self("Durchlauf '{$id}' nicht gefunden.");
    }

    public static function step(string $definitionId, string $step): self
    {
        return new self("Unbekannter Schritt '{$step}' in Workflow '{$definitionId}'.");
    }

    public static function action(string $key): self
    {
        return new self("Keine Aktion für Schlüssel '{$key}' registriert.");
    }
}
