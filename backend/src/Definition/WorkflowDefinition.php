<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

use WorkflowEngine\Exception\NotFoundException;

/**
 * Eine Version eines Ablaufs: ein gerichteter Graph aus Schritten und Übergängen.
 * Unveränderlich; laufende Durchläufe bleiben auf ihrer Version (ADR 3).
 */
final readonly class WorkflowDefinition
{
    /**
     * @param array<string, Step>  $steps
     * @param list<WorkflowInput>  $inputs
     */
    public function __construct(
        public string $id,
        public int $version,
        public string $name,
        public string $startStep,
        public array $steps,
        public array $inputs = [],
    ) {
    }

    public function step(string $name): Step
    {
        return $this->steps[$name] ?? throw NotFoundException::step($this->id, $name);
    }

    public function hasStep(string $name): bool
    {
        return isset($this->steps[$name]);
    }

    /**
     * Die Namen aller fehlenden Pflichtwerte, in der Reihenfolge der Deklaration.
     *
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    public function missingInputs(array $context): array
    {
        $missing = [];
        foreach ($this->inputs as $input) {
            if ($input->isMissingIn($context)) {
                $missing[] = $input->name;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'id' => $this->id,
            'version' => $this->version,
            'name' => $this->name,
            'startStep' => $this->startStep,
        ];
        if ($this->inputs !== []) {
            $out['inputs'] = array_map(static fn (WorkflowInput $i): array => $i->toArray(), $this->inputs);
        }
        $out['steps'] = array_map(static fn (Step $s): array => $s->toArray(), $this->steps);

        return $out;
    }
}
