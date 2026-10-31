<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Contracts\PersistenceInterface;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelException;

/**
 * Die Persistenzen nach Namen. Eine Host-App kann mehrere gleichzeitig nutzen und eigene
 * ergänzen (F-129, F-130), etwa für Daten, die nur über einen eigenen Dienst geschrieben
 * werden dürfen.
 */
final class PersistenceRegistry
{
    /**
     * @param array<string, PersistenceInterface> $persistences
     */
    public function __construct(private array $persistences = [])
    {
    }

    public function register(string $name, PersistenceInterface $persistence): void
    {
        $this->persistences[$name] = $persistence;
    }

    public function for(DataModel $model): PersistenceInterface
    {
        return $this->persistences[$model->persistence]
            ?? throw DataModelException::unknownPersistence($model->name, $model->persistence);
    }
}
