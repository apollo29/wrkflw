<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/** Anmeldeart einer REST-Verbindung (F-134, 9.16). */
enum AuthType: string
{
    case None = 'none';
    case Bearer = 'bearer';
    case ApiKey = 'api_key';
    case Basic = 'basic';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Keine',
            self::Bearer => 'Bearer-Token',
            self::ApiKey => 'API-Schlüssel',
            self::Basic => 'Benutzername und Passwort',
        };
    }

    public function needsSecret(): bool
    {
        return $this !== self::None;
    }
}
