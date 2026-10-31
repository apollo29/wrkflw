<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelSourceInterface;

/** Eine Quelle, der Tests jederzeit Modelle hinzufügen können. */
final class ArrayDataModelSource implements DataModelSourceInterface
{
    /** @var list<DataModel> */
    private array $models = [];

    public function add(DataModel $model): void
    {
        $this->models[] = $model;
    }

    public function models(): iterable
    {
        return $this->models;
    }
}
