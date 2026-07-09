<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Persistenz wiederverwendbarer Templates. Ein Template buendelt Betreff und
 * HTML-Body mit {{platzhalter}}, die zur Laufzeit aus dem Instanz-Kontext ersetzt
 * werden. Der Typ unterscheidet 'email' (send_email-Action) von 'page' (HTML-Seite,
 * die ein interaktiver Schritt anzeigt). Workflow-Schritte referenzieren ein Template
 * ueber seine id.
 */
interface TemplateRepositoryInterface
{
    /**
     * @param string|null $type Nur Templates dieses Typs ('email'|'page'); null = alle
     *
     * @return list<array{id:string,name:string,type:string}>
     */
    public function listTemplates(?string $type = null): array;

    /**
     * @return array{id:string,name:string,type:string,subject:string,body:string}|null
     */
    public function findTemplate(string $id): ?array;

    public function saveTemplate(
        string $id,
        string $name,
        string $subject,
        string $body,
        string $type = 'email',
    ): void;

    public function deleteTemplate(string $id): void;
}
