<?php

declare(strict_types=1);

namespace WorkflowEngine\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionStatus;
use WorkflowEngine\Definition\DefinitionSummary;
use WorkflowEngine\Definition\VersionDeleteBlock;
use WorkflowEngine\Definition\VersionSummary;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\InvalidDefinitionException;
use WorkflowEngine\Exception\NotFoundException;

/**
 * Definitionen für Übersicht und Editor (E-60 bis E-66, F-10 bis F-13).
 *
 * «Aktiv» speichert nur eine fehlerfreie Definition. Entwurf und Inaktiv dürfen Fehler
 * enthalten, solange die Grundform stimmt; die Antwort nennt sie (`validation`).
 */
final readonly class DefinitionController
{
    public function __construct(private ApiServices $s)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return JsonIo::json($response, [
            'definitions' => array_map(static fn (DefinitionSummary $d): array => [
                'id' => $d->id,
                'name' => $d->name,
                'version' => $d->version,
                'status' => $d->status->value,
                'updatedAt' => JsonIo::time($d->updatedAt),
                'runningInstances' => $d->runningInstances,
                'totalInstances' => $d->totalInstances,
                'olderVersions' => $d->olderVersions,
                'structure' => $d->structure,
            ], $this->s->definitions->listDefinitions()),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'def');
        $versions = $this->s->definitions->versions($id);
        if ($versions === []) {
            throw NotFoundException::definition($id);
        }

        return $this->version($response, $id, $versions[0], true);
    }

    /**
     * @param array<string, string> $args
     */
    public function getVersion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'def');
        $number = JsonIo::intArg($args, 'version');
        foreach ($this->s->definitions->versions($id) as $index => $version) {
            if ($version->version === $number) {
                return $this->version($response, $id, $version, $index === 0);
            }
        }

        throw NotFoundException::definition($id, $number);
    }

    /**
     * @param array<string, string> $args
     */
    public function versions(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'def');
        $versions = $this->s->definitions->versions($id);
        if ($versions === []) {
            throw NotFoundException::definition($id);
        }

        return JsonIo::json($response, [
            'id' => $id,
            'versions' => array_map(static fn (VersionSummary $v): array => self::versionInfo($v), $versions),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'def');
        $body = JsonIo::body($request);
        $status = self::status($body);
        $definition = $this->parse($body, $id);

        $report = $this->s->validator->check($definition);
        if ($status === DefinitionStatus::Active && !$report->isValid()) {
            throw InvalidDefinitionException::fromReport($report);
        }
        $saved = $this->s->definitions->save($definition, $status);

        return JsonIo::json($response, [
            'id' => $saved->id,
            'version' => $saved->version,
            'status' => $saved->status->value,
            'newVersion' => $saved->newVersion,
            'validation' => $report->toArray(),
        ], 201);
    }

    /** Nur prüfen, nichts speichern (Live-Prüfung im Editor). */
    public function validate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = JsonIo::body($request);
        try {
            $report = $this->s->validator->check($this->parse($body, null));
        } catch (InvalidDefinitionException $e) {
            return JsonIo::json($response, [
                'valid' => false,
                'errors' => $e->report()?->toArray() ?? array_map(static fn (string $m): array => ['code' => 'invalid', 'message' => $m, 'step' => null, 'transition' => null], $e->errors()),
            ]);
        }

        return JsonIo::json($response, ['valid' => $report->isValid(), 'errors' => $report->toArray()]);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteVersion(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = JsonIo::arg($args, 'def');
        $version = JsonIo::intArg($args, 'version');
        $block = $this->s->definitions->deleteVersion($id, $version);
        if ($block === VersionDeleteBlock::NotFound) {
            throw NotFoundException::definition($id, $version);
        }
        if ($block !== null) {
            // Einheitliches Fehlerformat (ADR 11); 2.x antwortete hier flach.
            throw new ConflictException($block->message(), 'not_deletable');
        }

        return JsonIo::empty($response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function parse(array $body, ?string $id): WorkflowDefinition
    {
        $data = JsonIo::assoc($body['definition'] ?? null);
        if ($data === []) {
            throw RequestValidationException::field('definition', "Das Feld 'definition' ist erforderlich.");
        }
        if ($id !== null) {
            // Die ID kommt aus dem Pfad; ohne Namen gilt die ID (Parser).
            $data['id'] = $id;
        }
        $name = JsonIo::string($body, 'name');
        if ($name !== null && trim($name) !== '') {
            $data['name'] = trim($name);
        }
        unset($data['version']);

        return DefinitionParser::fromArray($data, 1);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function status(array $body): DefinitionStatus
    {
        $raw = JsonIo::string($body, 'status') ?? DefinitionStatus::Active->value;

        return DefinitionStatus::tryFrom($raw)
            ?? throw RequestValidationException::field('status', "Unbekannter Status '{$raw}'; erlaubt sind active, inactive und draft.");
    }

    private function version(ResponseInterface $response, string $id, VersionSummary $version, bool $latest): ResponseInterface
    {
        $json = $this->s->definitions->findJson($id, $version->version) ?? throw NotFoundException::definition($id, $version->version);

        return JsonIo::json($response, [
            'id' => $id,
            'version' => $version->version,
            'status' => $version->status->value,
            // Ältere Versionen sind schreibgeschützt (E-65).
            'readOnly' => !$latest,
            'updatedAt' => JsonIo::time($version->updatedAt),
            'definition' => json_decode($json, false, 512, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function versionInfo(VersionSummary $v): array
    {
        return [
            'version' => $v->version,
            'name' => $v->name,
            'status' => $v->status->value,
            'createdAt' => JsonIo::time($v->createdAt),
            'updatedAt' => JsonIo::time($v->updatedAt),
            'runningInstances' => $v->runningInstances,
            'totalInstances' => $v->totalInstances,
            'deletable' => $v->isDeletable(),
            'deleteBlockedBy' => $v->deleteBlockedBy?->value,
        ];
    }
}
