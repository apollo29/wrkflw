<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use WorkflowEngine\Exception\WorkflowException;

/** Eine Anfrage ist unvollständig oder falsch geformt (422). */
final class RequestValidationException extends WorkflowException
{
    /**
     * @param array<string, list<string>> $errors Feld => Meldungen
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    public static function field(string $field, string $message): self
    {
        return new self($message, [$field => [$message]]);
    }
}
