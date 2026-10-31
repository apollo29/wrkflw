<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Definition\DefinitionStatus;
use WorkflowEngine\Definition\DefinitionSummary;
use WorkflowEngine\Definition\SavedVersion;
use WorkflowEngine\Definition\VersionDeleteBlock;
use WorkflowEngine\Definition\VersionSummary;
use WorkflowEngine\Definition\WorkflowDefinition;

/**
 * Verwaltung der Definitionen für Editor und API (F-10 bis F-13).
 *
 * Speicherregel:
 * - «Aktiv» erzeugt immer eine neue Version (F-11).
 * - «Entwurf»/«Inaktiv» überschreibt die aktuelle Fassung, solange kein Durchlauf auf sie
 *   verweist; sonst entsteht eine neue Version, damit laufende Durchläufe ihre Definition
 *   behalten (F-12, ADR 3).
 * - Startbar ist ein Ablauf nur, wenn seine neueste Version «Aktiv» ist.
 */
interface DefinitionRepositoryInterface
{
    public function save(WorkflowDefinition $definition, DefinitionStatus $status): SavedVersion;

    /**
     * Eine Zeile je Ablauf (E-60).
     *
     * @return list<DefinitionSummary>
     */
    public function listDefinitions(): array;

    /**
     * Alle Versionen eines Ablaufs, neueste zuerst (E-65).
     *
     * @return list<VersionSummary>
     */
    public function versions(string $id): array;

    /**
     * Entfernt eine ältere Version ohne Durchläufe.
     *
     * @return VersionDeleteBlock|null null, wenn entfernt; sonst der Grund
     */
    public function deleteVersion(string $id, int $version): ?VersionDeleteBlock;

    /** Das gespeicherte JSON einer Version (ohne Angabe: der neuesten). */
    public function findJson(string $id, ?int $version = null): ?string;

    /**
     * @return list<TemplateUsage>
     */
    public function templateUsage(string $templateId): array;
}
