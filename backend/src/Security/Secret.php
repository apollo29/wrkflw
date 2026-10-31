<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

/**
 * Ein entschlüsseltes Geheimnis. Der Klartext ist nur über `reveal()` erreichbar; als Text,
 * in JSON, `print_r` und `var_dump` erscheint `***`, damit er nicht versehentlich in Logs,
 * Fehlermeldungen oder API-Antworten gerät (F-135).
 */
final class Secret implements \Stringable, \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] private readonly string $value)
    {
    }

    public function reveal(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return '***';
    }

    public function jsonSerialize(): string
    {
        return '***';
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /**
     * Auch `print_r` und `var_export` greifen auf die Eigenschaften zu; serialisieren ist gesperrt.
     *
     * @return array<never>
     */
    public function __serialize(): array
    {
        throw new SecretException('Geheimnisse lassen sich nicht serialisieren.');
    }
}
