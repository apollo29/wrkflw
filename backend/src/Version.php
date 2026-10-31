<?php

declare(strict_types=1);

namespace WorkflowEngine;

/**
 * Version der Engine und der HTTP-API.
 *
 * Jede Änderung an einem Port oder an der API-Form ist eine neue Hauptversion
 * (Q-10). `API` steigt nur mit der Hauptversion.
 */
final class Version
{
    public const string ENGINE = '3.0.0-dev';

    public const int API = 3;

    private function __construct()
    {
    }

    public static function major(): int
    {
        return (int) explode('.', self::ENGINE, 2)[0];
    }
}
