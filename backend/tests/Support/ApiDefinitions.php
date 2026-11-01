<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

/** Definitionen für die API-Tests. */
final class ApiDefinitions
{
    /**
     * Antrag mit einem geänderten Schritt: `$replace` ergänzt oder ersetzt dessen Angaben
     * (`$merge = false`: ersetzt den ganzen Schritt).
     *
     * @param array<string, mixed> $replace
     *
     * @return array<string, mixed>
     */
    public static function antragWith(string $name, array $replace, bool $merge = true): array
    {
        $definition = self::antrag();
        $steps = $definition['steps'];
        assert(is_array($steps));
        $current = $merge && is_array($steps[$name] ?? null) ? $steps[$name] : [];
        $steps[$name] = array_merge($current, $replace);
        $definition['steps'] = $steps;

        return $definition;
    }

    /**
     * Antrag mit Pflichtangabe, zwei interaktiven Schritten und «Ein Schritt zurück».
     *
     * @return array<string, mixed>
     */
    public static function antrag(string $id = 'antrag'): array
    {
        return [
            'id' => $id,
            'name' => 'Antrag prüfen',
            'startStep' => 'erfassen',
            'inputs' => [['name' => 'antragId', 'label' => 'Antrag', 'required' => true]],
            'steps' => [
                'erfassen' => [
                    'type' => 'interactive',
                    'ui' => ['title' => 'Angaben', 'fields' => [['name' => 'betrag', 'type' => 'number']]],
                    'transitions' => [['to' => 'pruefen', 'event' => 'weiter', 'label' => 'Weiter']],
                ],
                'pruefen' => [
                    'type' => 'interactive',
                    'ui' => ['back' => true],
                    'transitions' => [
                        ['to' => 'ende', 'event' => 'freigeben', 'label' => 'Freigeben'],
                        ['to' => 'ende', 'event' => 'ablehnen'],
                    ],
                ],
                'ende' => [],
            ],
        ];
    }
}
