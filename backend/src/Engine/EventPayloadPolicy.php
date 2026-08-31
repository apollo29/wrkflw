<?php

declare(strict_types=1);

namespace WorkflowEngine\Engine;

/**
 * Wie streng ein Event-Payload gegen die Felder des aktuellen Schritts geprueft
 * wird (ADR 0006).
 *
 * Unabhaengig davon werden engine-interne Schluessel ("__", siehe
 * {@see \WorkflowEngine\Instance\ContextKeys}) IMMER verworfen — das ist keine
 * Frage der Policy.
 *
 * Der Weg fuer eine bestehende Anwendung ist Allow -> Report -> Enforce:
 * erst mitschreiben, was wegfiele, dann scharf schalten.
 */
enum EventPayloadPolicy
{
    /** Nur interne Schluessel werden verworfen. Rueckwaertskompatibler Default. */
    case Allow;

    /**
     * Wie Allow, protokolliert aber zusaetzlich, was Enforce verwerfen wuerde
     * (History-Art `event_payload_rejected`, Feld `wouldDrop`).
     */
    case Report;

    /**
     * Nur die in `ui.fields` deklarierten Feldnamen kommen durch. Deklariert ein
     * Schritt keine Felder, kommt nichts durch — fail closed.
     */
    case Enforce;
}
