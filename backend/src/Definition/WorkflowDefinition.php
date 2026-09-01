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
    /**
     * @param array<string,Step>   $steps
     * @param list<WorkflowInput>  $inputs deklarierter Startkontext; leer = nicht deklariert
     */
    private function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $name,
        public readonly string $startStep,
        public readonly array $steps,
        public readonly array $inputs = [],
    ) {
    }

    /**
     * Welche deklarierten Pflichtangaben in diesem Kontext fehlen?
     *
     * Leere Liste heisst «alles da» — und bei einer Definition ohne
     * Deklaration immer, denn dann gibt es nichts zu pruefen.
     *
     * @param array<string,mixed> $context
     *
     * @return list<string> die Namen, in der Reihenfolge der Deklaration
     */
    public function missingInputs(array $context): array
    {
        $fehlt = [];
        foreach ($this->inputs as $input) {
            if ($input->fehltIn($context)) {
                $fehlt[] = $input->name;
            }
        }

        return $fehlt;
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

        // Fehlt `inputs` ganz, ist nichts deklariert — bestehende Definitionen
        // bleiben damit unveraendert gueltig.
        $inputs = [];
        foreach (is_array($d['inputs'] ?? null) ? $d['inputs'] : [] as $rohInput) {
            if (!is_array($rohInput)) {
                continue;
            }
            /** @var array<string,mixed> $rohInput */
            $input = WorkflowInput::fromArray($rohInput);
            if ($input !== null) {
                $inputs[] = $input;
            }
        }

        return new self(
            id: $id,
            version: $version,
            name: $name,
            startStep: $startStep,
            steps: $steps,
            inputs: $inputs,
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
