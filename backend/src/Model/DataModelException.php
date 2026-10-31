<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

use WorkflowEngine\Contracts\PermanentFailureInterface;
use WorkflowEngine\Exception\WorkflowException;

/**
 * Ein Datenmodell fehlt, ist falsch beschrieben oder erlaubt den Vorgang nicht. Eine
 * Wiederholung hilft nicht; der Durchlauf schlägt sofort fehl.
 */
final class DataModelException extends WorkflowException implements PermanentFailureInterface
{
    public static function unknownModel(string $name): self
    {
        return new self("Datenmodell '{$name}' ist nicht bekannt.");
    }

    public static function unknownField(string $model, string $field): self
    {
        return new self("Das Datenmodell '{$model}' hat kein Feld '{$field}'.");
    }

    public static function notWritable(string $model, string $field): self
    {
        return new self("Feld '{$field}' im Datenmodell '{$model}' ist nicht schreibbar.");
    }

    public static function operationNotAllowed(string $model, string $operation): self
    {
        return new self("Das Datenmodell '{$model}' erlaubt kein {$operation}.");
    }

    public static function duplicate(string $name): self
    {
        return new self("Datenmodell '{$name}' ist mehrfach definiert.");
    }

    public static function unknownPersistence(string $model, string $persistence): self
    {
        return new self("Das Datenmodell '{$model}' verwendet die unbekannte Persistenz '{$persistence}'.");
    }

    public static function invalid(string $model, string $reason): self
    {
        return new self("Datenmodell '{$model}' ist ungültig: {$reason}");
    }
}
