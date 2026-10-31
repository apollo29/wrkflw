<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\RuleInterface;

/** Datenmodelle für Tests, nach dem Beispiel aus Abschnitt 5.6. */
final class Models
{
    public static function antrag(RuleInterface ...$rules): DataModel
    {
        $builder = DataModel::define('antrag', 'Anträge')
            ->text('status', 'Status')->writable()
            ->number('betrag', 'Betrag')
            ->text('mail', 'E-Mail')
            ->date('geprueft_am', 'Geprüft am')->writable()
            ->subset(['archiviert' => 0])
            ->updatable();
        foreach ($rules as $rule) {
            $builder->rule($rule);
        }

        return $builder->build();
    }

    /**
     * Ein Datensatz in Ablage-Namen, wie ihn die Persistenz sieht.
     *
     * @return array<string, mixed>
     */
    public static function antragRow(string $id, int $betrag = 1250, int $archiviert = 0): array
    {
        return [
            'id' => $id,
            'status' => 'offen',
            'betrag' => $betrag,
            'mail' => 'p@example.org',
            'geprueft_am' => null,
            'archiviert' => $archiviert,
        ];
    }
}
