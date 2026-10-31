<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Exception\InvalidDefinitionException;

/**
 * Liest Definitionen aus JSON oder einem Array. Das Format ist das von 2.x (F-114).
 *
 * Der Parser prüft nur die Form (Typen, Pflichtfelder, doppelte Schlüssel). Ob der
 * Graph fachlich stimmt, prüft der {@see DefinitionValidator}.
 */
final class DefinitionParser
{
    private function __construct()
    {
    }

    /**
     * @param int|null $version überschreibt eine Version im JSON (die Ablage vergibt sie)
     */
    public static function fromJson(string $json, ?int $version = null): WorkflowDefinition
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidDefinitionException(["Kein gültiges JSON: {$e->getMessage()}."], null, $e);
        }
        if (!is_array($data)) {
            throw InvalidDefinitionException::single('Die Definition muss ein JSON-Objekt sein.');
        }

        $duplicates = JsonDuplicateKeys::find($json);
        if ($duplicates !== []) {
            throw new InvalidDefinitionException(array_map(
                static fn (string $path): string => preg_match('/^steps\.([^.]+)$/', $path, $m) === 1
                    ? "Schrittname '{$m[1]}' kommt mehrfach vor."
                    : "Schlüssel '{$path}' kommt mehrfach vor.",
                $duplicates,
            ));
        }

        return self::fromArray($data, $version);
    }

    /**
     * @param array<mixed> $d
     */
    public static function fromArray(array $d, ?int $version = null): WorkflowDefinition
    {
        $id = $d['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw InvalidDefinitionException::single('Die Definition hat keine gültige ID.');
        }

        $startStep = $d['startStep'] ?? null;
        if (!is_string($startStep) || $startStep === '') {
            throw InvalidDefinitionException::single("Workflow '{$id}' hat keinen gültigen Start-Schritt.");
        }

        if ($version === null) {
            $version = $d['version'] ?? 1;
            if (!is_int($version)) {
                throw InvalidDefinitionException::single("Workflow '{$id}' hat eine ungültige Version.");
            }
        }

        $name = $d['name'] ?? null;

        $rawSteps = $d['steps'] ?? null;
        if (!is_array($rawSteps) || $rawSteps === []) {
            throw InvalidDefinitionException::single("Workflow '{$id}' hat keine Schritte.");
        }
        $steps = [];
        foreach ($rawSteps as $stepName => $stepData) {
            $stepName = (string) $stepName;
            if (!is_array($stepData)) {
                throw InvalidDefinitionException::single("Schritt '{$stepName}' ist kein Objekt.");
            }
            $steps[$stepName] = Step::fromArray($stepName, $stepData);
        }

        $inputs = [];
        $rawInputs = $d['inputs'] ?? [];
        foreach (is_array($rawInputs) ? $rawInputs : [] as $rawInput) {
            $input = is_array($rawInput) ? WorkflowInput::fromArray($rawInput) : null;
            if ($input !== null) {
                $inputs[] = $input;
            }
        }

        return new WorkflowDefinition(
            id: $id,
            version: $version,
            name: is_string($name) && trim($name) !== '' ? $name : $id,
            startStep: $startStep,
            steps: $steps,
            inputs: $inputs,
        );
    }
}
