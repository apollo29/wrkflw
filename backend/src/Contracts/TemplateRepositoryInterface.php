<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Persistenz wiederverwendbarer (E-Mail-)Templates. Ein Template buendelt
 * Betreff und HTML-Body mit {{platzhalter}}, die zur Laufzeit aus dem Instanz-
 * Kontext ersetzt werden. Workflow-Schritte referenzieren ein Template ueber seine id.
 */
interface TemplateRepositoryInterface
{
    /**
     * @return list<array{id:string,name:string}>
     */
    public function listTemplates(): array;

    /**
     * @return array{id:string,name:string,subject:string,body:string}|null
     */
    public function findTemplate(string $id): ?array;

    public function saveTemplate(string $id, string $name, string $subject, string $body): void;
}
