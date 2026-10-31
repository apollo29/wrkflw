<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Liefert Datenmodelle: aus dem Code der Host-App oder (ab Phase 4) aus den
 * REST-Datenquellen, die ein Admin einrichtet.
 */
interface DataModelSourceInterface
{
    /**
     * @return iterable<DataModel>
     */
    public function models(): iterable;
}
