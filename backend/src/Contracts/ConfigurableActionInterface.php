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
     * @return list<array{name:string,label:string,type:string}> type: text|textarea|boolean|number
     */
    public function configSchema(): array;
}
