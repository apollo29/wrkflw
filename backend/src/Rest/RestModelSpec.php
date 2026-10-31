<?php

declare(strict_types=1);

namespace WorkflowEngine\Rest;

use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\Field;
use WorkflowEngine\Model\FieldType;

/**
 * Ein REST-Datenmodell, wie ein Admin es einrichtet (F-131, 9.16): Vorgänge mit Methode
 * und Pfad, Trefferliste der Suche, Teilmenge als Query-Parameter und Felder mit Pfad in der
 * Antwort. Ein leerer Pfad heisst: Vorgang nicht verfügbar.
 *
 * Für einen Ablauf sieht das daraus abgeleitete {@see DataModel} genauso aus wie eines auf
 * einer Tabelle (5.6).
 */
final readonly class RestModelSpec
{
    public const array OPERATIONS = ['load' => 'GET', 'find' => 'GET', 'update' => 'PATCH', 'create' => 'POST'];

    private const array METHODS = ['load' => ['GET'], 'find' => ['GET', 'POST'], 'update' => ['PATCH', 'PUT', 'POST'], 'create' => ['POST', 'PUT']];

    private const string PATH_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)*$/';

    /** @var array<string, RestOperation> */
    public array $operations;

    /**
     * @param array<string, RestOperation> $operations nur verfügbare Vorgänge
     * @param list<RestFieldSpec>          $fields
     * @param array<string, string>        $subset     Query-Parameter, die jede Anfrage mitschickt
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $connectionId,
        array $operations,
        public array $fields,
        public string $idField = 'id',
        public string $listPath = '',
        public array $subset = [],
    ) {
        $valid = [];
        foreach ($operations as $kind => $operation) {
            if (!isset(self::OPERATIONS[$kind])) {
                throw new RestSpecException("Unbekannter Vorgang '{$kind}'.");
            }
            if (trim($operation->path) === '') {
                continue;
            }
            if (!in_array(strtoupper($operation->method), self::METHODS[$kind], true)) {
                throw new RestSpecException("Methode {$operation->method} passt nicht zum Vorgang '{$kind}'.");
            }
            if (in_array($kind, ['load', 'update'], true) && !str_contains($operation->path, '{id}')) {
                throw new RestSpecException("Der Pfad für '{$kind}' braucht den Platzhalter {id}.");
            }
            $valid[$kind] = new RestOperation(strtoupper($operation->method), trim($operation->path));
        }
        $this->operations = $valid;

        if ($fields === []) {
            throw new RestSpecException("Datenmodell '{$name}' hat keine Felder.");
        }
        foreach ($fields as $field) {
            if (preg_match(self::PATH_PATTERN, $field->path) !== 1) {
                throw new RestSpecException("Feld '{$field->name}': der Pfad '{$field->path}' ist ungültig (z. B. kontakt.mail).");
            }
        }
        if ($listPath !== '' && preg_match(self::PATH_PATTERN, $listPath) !== 1) {
            throw new RestSpecException("Die Trefferliste '{$listPath}' ist kein gültiger Pfad.");
        }
    }

    public function operation(string $kind): ?RestOperation
    {
        return $this->operations[$kind] ?? null;
    }

    public function field(string $name): ?RestFieldSpec
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param bool $allowWrites false, wenn die Host-App Schreiben über REST sperrt (F-137)
     */
    public function toDataModel(bool $allowWrites): DataModel
    {
        $canUpdate = $allowWrites && isset($this->operations['update']);
        $canCreate = $allowWrites && isset($this->operations['create']);
        $fields = array_map(
            fn (RestFieldSpec $f): Field => new Field(
                $f->name,
                $f->label,
                $f->type,
                $f->path === $f->name ? null : $f->path,
                $f->writable && ($canUpdate || $canCreate) && $f->name !== $this->idField,
            ),
            $this->fields,
        );

        return new DataModel(
            name: $this->name,
            label: $this->label,
            idField: $this->idField,
            fields: $fields,
            persistence: 'rest',
            subset: $this->subset,
            canUpdate: $canUpdate,
            canCreate: $canCreate,
        );
    }

    /**
     * @param array<mixed> $d
     */
    public static function fromArray(array $d): self
    {
        $text = static fn (string $key, string $default = ''): string => is_string($d[$key] ?? null) ? trim($d[$key]) : $default;
        $name = $text('name');
        $label = $text('label');
        $connection = $text('connection');
        if ($name === '' || $label === '' || $connection === '') {
            throw new RestSpecException('Name, Bezeichnung und Verbindung sind Pflicht.');
        }

        $operations = [];
        foreach (is_array($d['operations'] ?? null) ? $d['operations'] : [] as $kind => $op) {
            if (is_array($op) && is_string($op['path'] ?? null)) {
                $method = is_string($op['method'] ?? null) && $op['method'] !== '' ? $op['method'] : (self::OPERATIONS[(string) $kind] ?? 'GET');
                $operations[(string) $kind] = new RestOperation($method, $op['path']);
            }
        }

        $fields = [];
        foreach (is_array($d['fields'] ?? null) ? $d['fields'] : [] as $f) {
            if (!is_array($f) || !is_string($f['name'] ?? null)) {
                throw new RestSpecException('Ein Feld hat keinen Namen.');
            }
            $type = FieldType::tryFrom(is_string($f['type'] ?? null) ? $f['type'] : 'text')
                ?? throw new RestSpecException("Feld '{$f['name']}' hat einen unbekannten Typ.");
            $fields[] = new RestFieldSpec(
                $f['name'],
                is_string($f['label'] ?? null) && $f['label'] !== '' ? $f['label'] : $f['name'],
                $type,
                is_string($f['path'] ?? null) && $f['path'] !== '' ? $f['path'] : $f['name'],
                ($f['writable'] ?? false) === true,
            );
        }

        $subset = [];
        foreach (is_array($d['subset'] ?? null) ? $d['subset'] : [] as $key => $value) {
            if (is_scalar($value)) {
                $subset[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return new self($name, $label, $connection, $operations, $fields, $text('idField', 'id'), $text('listPath'), $subset);
    }

    /**
     * @return array{name: string, label: string, connection: string, idField: string, listPath: string, subset: array<string, string>, operations: array<string, array{method: string, path: string}>, fields: list<array{name: string, label: string, type: string, path: string, writable: bool}>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'connection' => $this->connectionId,
            'idField' => $this->idField,
            'listPath' => $this->listPath,
            'subset' => $this->subset,
            'operations' => array_map(static fn (RestOperation $o): array => ['method' => $o->method, 'path' => $o->path], $this->operations),
            'fields' => array_map(static fn (RestFieldSpec $f): array => [
                'name' => $f->name,
                'label' => $f->label,
                'type' => $f->type->value,
                'path' => $f->path,
                'writable' => $f->writable,
            ], $this->fields),
        ];
    }
}
