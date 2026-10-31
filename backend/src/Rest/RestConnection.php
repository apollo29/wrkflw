<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

/**
 * Adresse und Anmeldung eines externen Systems (F-134). Die Zugangsdaten liegen nur
 * verschlüsselt vor; es gibt keinen Weg, sie über dieses Objekt im Klartext zu erhalten
 * (F-135). Entschlüsselt wird erst beim Senden einer Anfrage.
 */
final readonly class RestConnection
{
    public function __construct(
        public string $id,
        public string $name,
        public string $baseUrl,
        public AuthType $authType = AuthType::None,
        public ?string $authHeader = null,
        public ?string $encryptedSecret = null,
    ) {
    }

    public function hasSecret(): bool
    {
        return $this->encryptedSecret !== null && $this->encryptedSecret !== '';
    }

    public function withEncryptedSecret(?string $encrypted): self
    {
        return new self($this->id, $this->name, $this->baseUrl, $this->authType, $this->authHeader, $encrypted);
    }

    /**
     * Sicht für API und Oberfläche: «Hinterlegt» statt der Zugangsdaten.
     *
     * @return array{id: string, name: string, baseUrl: string, authType: string, authHeader: ?string, secretStored: bool}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'baseUrl' => $this->baseUrl,
            'authType' => $this->authType->value,
            'authHeader' => $this->authHeader,
            'secretStored' => $this->hasSecret(),
        ];
    }
}
