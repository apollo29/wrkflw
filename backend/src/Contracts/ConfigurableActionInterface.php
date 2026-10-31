<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Eine Aktion, die ihre Einstellungen beschreibt. Der Editor zeichnet daraus passende
 * Eingabefelder, ohne die Aktion zu kennen (F-40, 9.7).
 *
 * Typen: text, number, textarea, boolean, html, template-ref, workflow-ref, entity-ref,
 * field-ref, field-ref-list, write-entity-ref, field-value-map. Unbekannte Typen zeigt
 * der Editor als Textfeld.
 */
interface ConfigurableActionInterface
{
    /** Lesbare Bezeichnung für die Auswahl im Editor (9.14 «Aktions-Auswahl»). */
    public function label(): string;

    /**
     * @return list<array{name: string, label: string, type: string}>
     */
    public function configSchema(): array;
}
