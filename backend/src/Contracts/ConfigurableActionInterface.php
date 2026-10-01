<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Optionales Zusatz-Interface fuer Actions, die ihr Config-Schema beschreiben.
 * Wird vom Action-Katalog (GET /actions) genutzt, damit ein Editor die passenden
 * Eingabefelder anbieten kann. Actions muessen dies nicht implementieren.
 */
interface ConfigurableActionInterface
{
    /**
     * Beschreibt die erwarteten Config-Felder der Action.
     *
     * Der Editor kennt neben den einfachen Typen (`text`, `textarea`, `boolean`,
     * `number`, `html`) auch solche, die ihre Auswahl von der Host-App holen:
     * `workflow-ref`, `template-ref`, `entity-ref` und `field-ref`/`field-ref-list`
     * (Lese-Katalog) sowie `write-entity-ref` und `field-value-map`
     * (Schreib-Katalog). Einen unbekannten Typ zeichnet er als Textfeld — eine
     * Aktion bleibt damit bedienbar, auch wenn der Client aelter ist.
     *
     * @return list<array{name:string,label:string,type:string}>
     */
    public function configSchema(): array;
}
