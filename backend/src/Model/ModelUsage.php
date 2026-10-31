<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/** Ein Schritt, der ein Datenmodell verwendet, mit den genannten Feldern (F-140). */
final readonly class ModelUsage
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public string $definitionId,
        public int $version,
        public string $step,
        public array $fields,
    ) {
    }

    /**
     * Findet die Datenschritte einer gespeicherten Definition, die das Modell nennen.
     *
     * @param array<mixed> $steps die `steps` einer Definition, roh
     *
     * @return list<self>
     */
    public static function scan(string $model, string $definitionId, int $version, array $steps): array
    {
        $usage = [];
        foreach ($steps as $name => $step) {
            if (!is_array($step) || !in_array($step['action'] ?? null, ['check_data', 'write_data'], true)) {
                continue;
            }
            $config = is_array($step['config'] ?? null) ? $step['config'] : [];
            if (($config['entity'] ?? null) !== $model) {
                continue;
            }
            $fields = [];
            if (is_string($config['field'] ?? null) && $config['field'] !== '') {
                $fields[] = $config['field'];
            }
            foreach (is_array($config['fields'] ?? null) ? $config['fields'] : [] as $field) {
                if (is_string($field)) {
                    $fields[] = $field;
                }
            }
            foreach (array_keys(is_array($config['values'] ?? null) ? $config['values'] : []) as $field) {
                $fields[] = (string) $field;
            }
            $usage[] = new self($definitionId, $version, (string) $name, array_values(array_unique($fields)));
        }

        return $usage;
    }
}
