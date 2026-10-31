<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

use WorkflowEngine\Security\Secret;
use WorkflowEngine\Security\SecretException;

/**
 * Verschlüsselt Zugangsdaten für die Ablage (F-135). Der Klartext verlässt die Engine nur
 * als Kopfzeile einer REST-Anfrage, nie als Antwort, Log oder Verlauf.
 */
interface SecretStoreInterface
{
    public function encrypt(string $plaintext): string;

    /**
     * @throws SecretException wenn der Text manipuliert oder mit unbekanntem Schlüssel verschlüsselt ist
     */
    public function decrypt(string $ciphertext): Secret;

    /** Ob der Text mit einem alten Schlüssel verschlüsselt ist und neu verschlüsselt werden sollte. */
    public function needsReencryption(string $ciphertext): bool;
}
