<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/** Alle Fehler einer Definition auf einmal (F-06). */
final readonly class ValidationReport
{
    /**
     * @param list<ValidationError> $errors
     */
    public function __construct(public array $errors = [])
    {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<ValidationError>
     */
    public function forStep(string $step): array
    {
        return array_values(array_filter($this->errors, static fn (ValidationError $e): bool => $e->step === $step));
    }

    /**
     * @return list<string>
     */
    public function messages(): array
    {
        return array_values(array_unique(array_map(static fn (ValidationError $e): string => $e->message, $this->errors)));
    }

    /**
     * @return list<array{code: string, message: string, step: ?string, transition: ?int}>
     */
    public function toArray(): array
    {
        return array_map(static fn (ValidationError $e): array => $e->toArray(), $this->errors);
    }
}
