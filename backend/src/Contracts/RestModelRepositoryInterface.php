<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Rest\RestModelSpec;

/** Ablage der REST-Datenmodelle. Nicht versioniert, Änderungen gelten sofort (F-141). */
interface RestModelRepositoryInterface
{
    public function findModel(string $name): ?RestModelSpec;

    /**
     * @return list<RestModelSpec>
     */
    public function allModels(): array;

    public function saveModel(RestModelSpec $model): void;

    public function deleteModel(string $name): void;
}
