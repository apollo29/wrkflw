<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: Liefert den Katalog der abfragbaren Entitaeten/Tabellen samt Feldern —
 * damit ein Editor fuer den Datencheck-Schritt Tabellen- und Feld-Dropdowns anbieten
 * kann. Die Host-App implementiert dies (dieselbe Whitelist wie im DataProvider).
 */
interface DataCatalogInterface
{
    /**
     * @return list<array{entity:string,label:string,fields:list<string>}>
     */
    public function entities(): array;
}
