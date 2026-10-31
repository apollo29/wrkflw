<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

use WorkflowEngine\Contracts\RestConnectionRepositoryInterface;
use WorkflowEngine\Contracts\SecretStoreInterface;

/**
 * Verschlüsselt alle Zugangsdaten mit dem aktuellen Schlüssel neu (ADR 10). Danach kann der
 * alte Schlüssel aus `WF_SECRET_KEYS_OLD` entfernt werden.
 */
final readonly class SecretRotation
{
    public function __construct(
        private RestConnectionRepositoryInterface $connections,
        private SecretStoreInterface $secrets,
    ) {
    }

    /**
     * @return int Anzahl neu verschlüsselter Verbindungen
     */
    public function run(): int
    {
        $count = 0;
        foreach ($this->connections->allConnections() as $connection) {
            $cipher = $connection->encryptedSecret;
            if ($cipher === null || $cipher === '' || !$this->secrets->needsReencryption($cipher)) {
                continue;
            }
            $plain = $this->secrets->decrypt($cipher);
            $this->connections->saveConnection($connection->withEncryptedSecret($this->secrets->encrypt($plain->reveal())));
            $count++;
        }

        return $count;
    }
}
