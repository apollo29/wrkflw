<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Contracts\SecretStoreInterface;
use WorkflowEngine\Security\HttpResult;
use WorkflowEngine\Security\SafeHttpClient;

/**
 * Sendet die Vorgänge eines REST-Datenmodells: Adresse aus Verbindung und Pfad, Teilmenge
 * als Query-Parameter, Anmeldung aus den entschlüsselten Zugangsdaten, Einordnung der
 * Fehlerarten (ADR 10).
 */
final readonly class RestClient
{
    private const array LABELS = ['load' => 'Laden', 'find' => 'Suchen', 'update' => 'Ändern', 'create' => 'Anlegen'];

    public function __construct(
        private SafeHttpClient $http,
        private SecretStoreInterface $secrets,
    ) {
    }

    /**
     * @param array<string, string>     $query
     * @param array<string, mixed>|null $body
     *
     * @throws RestTransportException technische Fehler (408, 425, 429, 5xx, Netz)
     * @throws RestRequestException   dauerhafte Fehler (andere 4xx, Vorgang fehlt)
     */
    public function send(RestConnection $connection, RestModelSpec $spec, string $kind, ?string $id, array $query = [], ?array $body = null): HttpResult
    {
        $operation = $spec->operation($kind)
            ?? throw new RestRequestException("REST-Datenmodell '{$spec->name}' erlaubt kein " . (self::LABELS[$kind] ?? $kind) . '.');

        $request = ['headers' => ['Accept' => 'application/json'] + $this->authHeaders($connection)];
        if ($body !== null) {
            $request['json'] = $body;
        }

        $result = $this->http->request($operation->method, $operation->url($connection->baseUrl, $id, $spec->subset + $query), $request);

        $status = $result->status;
        if ($status === 404 || ($status >= 200 && $status < 300)) {
            return $result;
        }
        $what = "REST-API '{$connection->id}' antwortete auf {$operation->method} ({$spec->name}) mit {$status}";
        if (in_array($status, [408, 425, 429], true) || $status >= 500) {
            throw new RestTransportException($what . '.');
        }

        throw new RestRequestException($what . '.', $status);
    }

    /**
     * Ein einfacher Aufruf der Basis-Adresse, für den Verbindungstest.
     */
    public function ping(RestConnection $connection): HttpResult
    {
        return $this->http->request('GET', $connection->baseUrl, ['headers' => ['Accept' => 'application/json'] + $this->authHeaders($connection)]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(RestConnection $connection): array
    {
        if (!$connection->authType->needsSecret()) {
            return [];
        }
        if (!$connection->hasSecret()) {
            throw new RestRequestException("Verbindung '{$connection->id}' hat keine Zugangsdaten hinterlegt.");
        }
        $secret = $this->secrets->decrypt((string) $connection->encryptedSecret)->reveal();

        return match ($connection->authType) {
            AuthType::Bearer => ['Authorization' => 'Bearer ' . $secret],
            AuthType::ApiKey => [$connection->authHeader !== null && $connection->authHeader !== '' ? $connection->authHeader : 'X-Api-Key' => $secret],
            AuthType::Basic => ['Authorization' => 'Basic ' . base64_encode($secret)],
            AuthType::None => [],
        };
    }
}
