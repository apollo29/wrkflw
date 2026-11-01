<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Datei-Prüfungen der Host-App für Felder vom Typ «Datei» (F-53). Die Engine nimmt selbst
 * keine Dateien an; der Editor bietet nur diese Namen zur Auswahl an.
 */
interface UploadHandlerCatalogInterface
{
    /**
     * @return list<UploadHandler>
     */
    public function handlers(): array;
}
