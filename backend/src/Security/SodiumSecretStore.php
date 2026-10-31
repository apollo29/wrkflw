<?php

declare(strict_types=1);

namespace WorkflowEngine\Security;

use WorkflowEngine\Contracts\SecretStoreInterface;

/**
 * Verschlüsselung mit libsodium `secretbox` (XSalsa20-Poly1305): zufällige Nonce je
 * Verschlüsselung, Manipulation fällt beim Entschlüsseln auf.
 *
 * Format: `v1:<key-id>:<base64(nonce . ciphertext)>`. Die Key-ID (8 Hex-Zeichen eines
 * Hashes) erlaubt den Schlüsselwechsel: der aktuelle Schlüssel verschlüsselt, alte Schlüssel
 * entschlüsseln nur noch (`bin/rotate-secret-key.php`).
 *
 * Schlüssel kommen aus der Umgebung (`WF_SECRET_KEY`, `WF_SECRET_KEYS_OLD`), nie aus der
 * Datenbank, in der die verschlüsselten Texte liegen (ADR 10).
 */
final class SodiumSecretStore implements SecretStoreInterface
{
    private const string PREFIX = 'v1';

    private readonly string $currentId;

    /** @var array<string, string> Key-ID => Schlüssel */
    private array $keys = [];

    /**
     * @param string       $currentKey base64, 32 Byte
     * @param list<string> $oldKeys    base64, nur zum Entschlüsseln
     */
    public function __construct(#[\SensitiveParameter] string $currentKey, #[\SensitiveParameter] array $oldKeys = [])
    {
        $this->currentId = $this->addKey($currentKey);
        foreach ($oldKeys as $key) {
            $this->addKey($key);
        }
    }

    public static function fromEnvironment(): self
    {
        $current = getenv('WF_SECRET_KEY');
        if (!is_string($current) || $current === '') {
            throw new SecretException('WF_SECRET_KEY ist nicht gesetzt; ohne Schlüssel lassen sich keine Zugangsdaten speichern.');
        }
        $old = getenv('WF_SECRET_KEYS_OLD');
        $oldKeys = is_string($old) && $old !== '' ? array_values(array_filter(array_map('trim', explode(',', $old)))) : [];

        return new self($current, $oldKeys);
    }

    /** Ein neuer zufälliger Schlüssel für `WF_SECRET_KEY`. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->keys[$this->currentId]);

        return self::PREFIX . ':' . $this->currentId . ':' . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $ciphertext): Secret
    {
        $parts = explode(':', $ciphertext, 3);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            throw new SecretException('Unbekanntes Format verschlüsselter Zugangsdaten.');
        }
        [, $keyId, $payload] = $parts;
        $key = $this->keys[$keyId] ?? throw new SecretException("Zugangsdaten sind mit einem unbekannten Schlüssel ({$keyId}) verschlüsselt.");

        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new SecretException('Verschlüsselte Zugangsdaten sind beschädigt.');
        }
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key,
        );
        if ($plain === false) {
            throw new SecretException('Verschlüsselte Zugangsdaten sind beschädigt oder manipuliert.');
        }

        return new Secret($plain);
    }

    public function needsReencryption(string $ciphertext): bool
    {
        $parts = explode(':', $ciphertext, 3);

        return count($parts) !== 3 || $parts[0] !== self::PREFIX || $parts[1] !== $this->currentId;
    }

    private function addKey(#[\SensitiveParameter] string $encoded): string
    {
        $key = base64_decode(trim($encoded), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new SecretException('Ein Schlüssel muss 32 Byte lang und base64-kodiert sein (bin/rotate-secret-key.php --generate).');
        }
        $id = substr(hash('sha256', 'wrkflw-key-id' . $key), 0, 8);
        $this->keys[$id] = $key;

        return $id;
    }
}
