<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/** Datenmodelle, die die Host-App im Code festlegt (F-113). */
final readonly class StaticDataModelSource implements DataModelSourceInterface
{
    /**
     * @param list<DataModel> $models
     */
    public function __construct(private array $models)
    {
    }

    public function models(): iterable
    {
        return $this->models;
    }
}
