<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Model\FieldType;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Rest\RestConnection;
use WorkflowEngine\Rest\RestFieldSpec;
use WorkflowEngine\Rest\RestModelSpec;
use WorkflowEngine\Rest\RestOperation;
use WorkflowEngine\Security\SodiumSecretStore;

/** Das REST-Beispiel aus 5.6: Verbindung `antrag-api`, Datenmodell `antrag`. */
final class RestFixtures
{
    public const string KEY = 'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    public static function secrets(): SodiumSecretStore
    {
        return new SodiumSecretStore(self::KEY);
    }

    public static function connection(AuthType $auth = AuthType::Bearer, ?string $secret = 'token-123', ?string $header = null): RestConnection
    {
        return new RestConnection(
            id: 'antrag-api',
            name: 'Antrags-API',
            baseUrl: 'https://api.example.org/v1',
            authType: $auth,
            authHeader: $header,
            encryptedSecret: $secret === null ? null : self::secrets()->encrypt($secret),
        );
    }

    public static function model(bool $creatable = false): RestModelSpec
    {
        $operations = [
            'load' => new RestOperation('GET', '/antraege/{id}'),
            'find' => new RestOperation('GET', '/antraege'),
            'update' => new RestOperation('PATCH', '/antraege/{id}'),
        ];
        if ($creatable) {
            $operations['create'] = new RestOperation('POST', '/antraege');
        }

        return new RestModelSpec(
            name: 'antrag',
            label: 'Anträge',
            connectionId: 'antrag-api',
            operations: $operations,
            fields: [
                new RestFieldSpec('id', 'ID', FieldType::Text, 'id'),
                new RestFieldSpec('status', 'Status', FieldType::Text, 'status', writable: true),
                new RestFieldSpec('betrag', 'Betrag', FieldType::Number, 'betrag'),
                new RestFieldSpec('mail', 'E-Mail', FieldType::Text, 'kontakt.mail'),
                new RestFieldSpec('geprueft_am', 'Geprüft am', FieldType::Date, 'geprueft_am', writable: true),
            ],
            listPath: 'data.items',
            subset: ['archiviert' => 'false'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function antragJson(string $id = 'A-1024', int $betrag = 1250): array
    {
        return [
            'id' => $id,
            'status' => 'offen',
            'betrag' => $betrag,
            'kontakt' => ['mail' => 'person@example.org', 'telefon' => '000'],
            'geprueft_am' => null,
            'iban' => 'CH00',
        ];
    }
}
