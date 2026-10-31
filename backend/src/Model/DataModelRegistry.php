<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Führt die Datenmodelle aller Quellen zusammen (5.6). Gleiche Namen sind ein Fehler:
 * REST-Datenmodelle ergänzen die der Host-App und ersetzen keines (F-138).
 *
 * Bewusst ohne Zwischenspeicher: Änderungen an Datenmodellen gelten sofort (F-141).
 */
final readonly class DataModelRegistry
{
    /**
     * @param iterable<DataModelSourceInterface> $sources
     */
    public function __construct(private iterable $sources)
    {
    }

    /**
     * @return array<string, DataModel>
     */
    public function all(): array
    {
        $models = [];
        foreach ($this->sources as $source) {
            foreach ($source->models() as $model) {
                if (isset($models[$model->name])) {
                    throw DataModelException::duplicate($model->name);
                }
                $models[$model->name] = $model;
            }
        }

        return $models;
    }

    public function get(string $name): DataModel
    {
        return $this->all()[$name] ?? throw DataModelException::unknownModel($name);
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }
}
