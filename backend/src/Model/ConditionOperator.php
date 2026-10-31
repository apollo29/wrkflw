<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/** Vergleiche für die Suche (F-122). */
enum ConditionOperator: string
{
    case Eq = '=';
    case Ne = '!=';
    case Lt = '<';
    case Le = '<=';
    case Gt = '>';
    case Ge = '>=';
    case Empty = 'empty';
    case NotEmpty = 'not_empty';

    public function needsValue(): bool
    {
        return $this !== self::Empty && $this !== self::NotEmpty;
    }
}
