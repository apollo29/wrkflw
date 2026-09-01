<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * PORT: Persistenz von Definitionen und Instanzen.
 * Default-Implementierung: PdoWorkflowRepository (MariaDB).
 */
interface WorkflowRepositoryInterface
{
    /**
     * Laedt eine Definition. Ohne Versionsangabe die neueste aktive Version.
     *
     * @throws \WorkflowEngine\Exception\WorkflowException wenn nicht gefunden
     */
    public function findDefinition(string $id, ?int $version = null): WorkflowDefinition;

    /**
     * Alle Definition-Versionen als Kurzuebersicht (fuer Verwaltung/Editor).
     * `active` markiert die aktuelle Version; `status` ist der Lebenszyklus
     * ('active'|'inactive'|'draft').
     *
     * `runningInstances` sind die noch laufenden Durchlaeufe dieser Version,
     * `instances` alle — auch abgeschlossene und abgebrochene. Der Unterschied
     * entscheidet, was mit einer alten Version geschehen darf: ohne laufende
     * Durchlaeufe stoert sie niemanden mehr und darf ins Archiv; loeschen
     * laesst sie sich erst, wenn ueberhaupt kein Durchlauf mehr auf sie zeigt
     * — sonst waeren die vergangenen Durchlaeufe nicht mehr lesbar, weil ihre
     * Definition fehlt.
     *
     * @return list<array{id:string,version:int,name:string,active:bool,status:string,instances:int,runningInstances:int}>
     */
    public function listDefinitions(): array;

    /**
     * Loescht EINE Version einer Definition.
     *
     * Liefert `false`, wenn sie nicht geloescht werden darf oder nicht
     * existiert — die neueste Version einer id bleibt immer stehen, und eine
     * Version, auf die noch ein Durchlauf zeigt, ebenfalls.
     */
    public function deleteDefinitionVersion(string $id, int $version): bool;

    /**
     * Rohes Definition-JSON (neueste aktive Version, oder eine bestimmte). Null,
     * wenn nicht vorhanden. Bewahrt die gespeicherte JSON-Form verlustfrei.
     */
    public function findDefinitionJson(string $id, ?int $version = null): ?string;

    /**
     * Speichert eine Definition und gibt die betroffene Versionsnummer zurueck.
     *
     * - $status = 'active': legt eine NEUE Version an (auto-inkrementiert), macht sie
     *   zur aktuellen und ausgelieferten Version (andere werden deaktiviert).
     * - $status = 'inactive'|'draft': legt KEINE neue Version an, sondern ueberschreibt
     *   die aktuelle Version in-place; die Definition wird dadurch nicht mehr
     *   ausgeliefert/getriggert (nur active=1 AND status='active' wird gestartet).
     *
     * @param string $status 'active'|'inactive'|'draft' (Unbekanntes faellt auf 'active')
     */
    public function saveDefinition(string $id, string $name, string $json, string $status = 'active'): int;

    public function saveInstance(WorkflowInstance $instance): void;

    public function findInstance(string $id): ?WorkflowInstance;

    /**
     * Instanzen, die der Cron-Runner aufwecken soll
     * (status = waiting_timer und wake_at <= jetzt). Reine Lese-Abfrage.
     *
     * @return list<WorkflowInstance>
     */
    public function findDueInstances(\DateTimeImmutable $now, int $limit = 50): array;

    /**
     * Faellige Timer-Instanzen NEBENLAEUFIGKEITSSICHER abholen: sperrt die Zeilen,
     * ueberspringt bereits von anderen Workern gesperrte Instanzen und markiert die
     * abgeholten als laufend, sodass parallele Cron-Laeufe dieselbe Instanz nicht
     * doppelt verarbeiten. Die zurueckgegebenen Instanzen haben Status RUNNING.
     *
     * Ist $staleAfterSeconds > 0, werden zusaetzlich "haengende" Instanzen
     * zurueckgeholt, die laenger als diese Spanne im Status RUNNING verharren
     * (z. B. weil ein Worker zwischen Claim und Verarbeitung abgestuerzt ist).
     *
     * @return list<WorkflowInstance>
     */
    public function claimDueInstances(
        \DateTimeImmutable $now,
        int $limit = 50,
        int $staleAfterSeconds = 0,
    ): array;

    /**
     * Schreibt einen Audit-/History-Eintrag.
     *
     * @param array<string,mixed> $detail
     */
    public function logHistory(
        string $instanceId,
        string $kind,
        ?string $step,
        array $detail = [],
    ): void;

    /**
     * Liest die History-Eintraege einer Instanz in chronologischer Reihenfolge.
     *
     * @return list<array{kind:string,step:string|null,detail:array<string,mixed>,createdAt:string}>
     */
    public function findHistory(string $instanceId): array;

    /**
     * Findet alle Definition-Schritte, die ein Template referenzieren
     * (config.templateId == $templateId) — fuer die Verwendungs-Anzeige.
     *
     * @return list<array{definitionId:string,version:int,step:string}>
     */
    public function findTemplateUsage(string $templateId): array;
}
