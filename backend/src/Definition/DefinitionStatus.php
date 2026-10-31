<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Lebenszyklus einer Definition (F-10). Nur «Aktiv» lässt sich starten und wird durch
 * Trigger ausgelöst; «Inaktiv» und «Entwurf» verhalten sich gleich.
 */
enum DefinitionStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Draft = 'draft';

    public function isStartable(): bool
    {
        return $this === self::Active;
    }
}
