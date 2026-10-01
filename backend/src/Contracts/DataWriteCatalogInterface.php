<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Liefert den Katalog der BESCHREIBBAREN Entitaeten samt erlaubter
 * Spalten — damit der Editor im Schreib-Schritt Tabellen- und Feld-Auswahl
 * anbieten kann. Das Gegenstueck zum {@see DataCatalogInterface}.
 *
 * WARUM NICHT DERSELBE KATALOG: der Lese-Katalog zeigt alles, was ein
 * Datencheck sehen darf — das ist deutlich mehr als das, was ein Ablauf
 * aendern darf. Wuerde der Editor die Lese-Liste anbieten, stuende dort jede
 * Spalte zur Auswahl, und die Haelfte davon wiese der Host beim Ausfuehren
 * ab. Eine Auswahl, die nachher nicht gilt, ist schlimmer als keine: der
 * Fehler faellt erst im Log auf, lange nach dem Speichern.
 *
 * Dieselbe Liste muss der {@see DataWriterInterface} benutzen. Zwei gepflegte
 * Listen driften.
 */
interface DataWriteCatalogInterface
{
    /**
     * @return list<array{entity:string,label:string,fields:list<string>}>
     */
    public function writableEntities(): array;
}
