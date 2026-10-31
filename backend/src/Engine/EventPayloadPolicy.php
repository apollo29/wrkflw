<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

/**
 * Wie streng Eingaben auf die angebotenen Felder beschränkt werden (F-102, ADR 6).
 * Engine-interne Schlüssel werden in jeder Stufe verworfen.
 */
enum EventPayloadPolicy: string
{
    /** Alle Felder übernehmen. */
    case Allow = 'allow';

    /** Alle übernehmen, nicht angebotene aber im Verlauf nennen. */
    case Report = 'report';

    /** Nur angebotene Eingabefelder übernehmen (Standard ab 3.0). */
    case Enforce = 'enforce';
}
