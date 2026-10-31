<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/** Typ eines Feldes (F-115). */
enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Bool = 'bool';
    case Date = 'date';
    case DateTime = 'datetime';

    public function label(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Number => 'Zahl',
            self::Bool => 'Ja/Nein',
            self::Date => 'Datum',
            self::DateTime => 'Zeitpunkt',
        };
    }
}
