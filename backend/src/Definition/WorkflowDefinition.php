<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Exception\InvalidDefinitionException;
use WorkflowEngine\Exception\WorkflowException;

/**
 * Die unveraenderliche Beschreibung eines Workflows (das "Template").
 * Wird als JSON in wf_definition.definition gespeichert.
 *
 * fromArray() prueft die Struktur (Typen, Pflichtfelder). Die graph-semantische
 * Pruefung (erreichbare Steps, gueltige Ziele, Zyklen) macht der DefinitionValidator.
 */
final class WorkflowDefinition
{
    /** @param array<string,Step> $steps */
    private function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $name,
        public readonly string $startStep,
        public readonly array $steps,
    ) {
    }

    /**
     * @param array<string,mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $id = $d['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw InvalidDefinitionException::single("Definition ohne gueltige 'id'.");
        }

        $startStep = $d['startStep'] ?? null;
        if (!is_string($startStep) || $startStep === '') {
            throw InvalidDefinitionException::single("Definition '{$id}' ohne gueltigen 'startStep'.");
        }

        $version = $d['version'] ?? 1;
        if (!is_int($version)) {
            throw InvalidDefinitionException::single("Definition '{$id}' hat eine ungueltige 'version'.");
        }

        $name = $d['name'] ?? $id;
        if (!is_string($name) || $name === '') {
            $name = $id;
        }

        $rawSteps = $d['steps'] ?? null;
        if (!is_array($rawSteps) || $rawSteps === []) {
            throw InvalidDefinitionException::single("Definition '{$id}' hat keine 'steps'.");
        }

        $steps = [];
        foreach ($rawSteps as $stepName => $stepDef) {
            $stepName = (string) $stepName;
            if (!is_array($stepDef)) {
                throw InvalidDefinitionException::single("Step '{$stepName}' ist kein Objekt.");
            }
            /** @var array<string,mixed> $stepDef */
            $steps[$stepName] = Step::fromArray($stepName, $stepDef);
        }

        return new self(
            id: $id,
            version: $version,
            name: $name,
            startStep: $startStep,
            steps: $steps,
        );
    }

    public function step(string $name): Step
    {
        return $this->steps[$name]
            ?? throw new WorkflowException("Unbekannter Step '{$name}' in Workflow '{$this->id}'.");
    }

    public function hasStep(string $name): bool
    {
        return isset($this->steps[$name]);
    }
}
