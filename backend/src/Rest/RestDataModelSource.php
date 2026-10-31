<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Contracts\RestModelRepositoryInterface;
use WorkflowEngine\Model\DataModelSourceInterface;

/**
 * Die vom Admin eingerichteten REST-Datenmodelle als Quelle der Registry (5.6). Ohne
 * Zwischenspeicher, damit Änderungen sofort gelten (F-141); gleiche Namen wie Modelle der
 * Host-App meldet die Registry als Fehler (F-138).
 */
final readonly class RestDataModelSource implements DataModelSourceInterface
{
    public function __construct(
        private RestModelRepositoryInterface $models,
        private RestOptions $options,
    ) {
    }

    public function models(): iterable
    {
        foreach ($this->models->allModels() as $spec) {
            yield $spec->toDataModel($this->options->allowWrites);
        }
    }
}
