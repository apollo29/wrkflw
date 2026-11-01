<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Tests\Support\ApiDefinitions;
use WorkflowEngine\Tests\Support\ApiHarness;

/** Definitionen über die API: Übersicht, Versionen, Speichern, Prüfen, Entfernen (E-60 bis E-66). */
final class DefinitionApiTest extends TestCase
{
    private ApiHarness $api;

    protected function setUp(): void
    {
        $this->api = new ApiHarness();
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function save(array $definition, string $status = 'active', ?string $id = null): \Psr\Http\Message\ResponseInterface
    {
        $id ??= is_string($definition['id'] ?? null) ? $definition['id'] : '';

        return $this->api->send('POST', "/workflows/{$id}", ['definition' => $definition, 'status' => $status]);
    }

    public function testSaveListAndGet(): void
    {
        $saved = $this->save(ApiDefinitions::antrag());
        self::assertSame(201, $saved->getStatusCode(), (string) $saved->getBody());
        self::assertSame(['id' => 'antrag', 'version' => 1, 'status' => 'active', 'newVersion' => true, 'validation' => []], ApiHarness::json($saved));

        $list = $this->api->send('GET', '/workflows');
        self::assertSame('antrag', ApiHarness::at($list, 'definitions.0.id'));
        self::assertSame('Antrag prüfen', ApiHarness::at($list, 'definitions.0.name'));
        self::assertSame('erfassen', ApiHarness::at($list, 'definitions.0.structure.startStep'));
        self::assertSame(0, ApiHarness::at($list, 'definitions.0.runningInstances'));
        self::assertNotNull(ApiHarness::at($list, 'definitions.0.updatedAt'));

        $get = $this->api->send('GET', '/workflows/antrag');
        self::assertSame(1, ApiHarness::at($get, 'version'));
        self::assertFalse(ApiHarness::at($get, 'readOnly'));
        self::assertSame('erfassen', ApiHarness::at($get, 'definition.startStep'));
        self::assertSame(404, $this->api->send('GET', '/workflows/gibts-nicht')->getStatusCode());
    }

    public function testIdComesFromThePath(): void
    {
        $saved = $this->save(ApiDefinitions::antrag('anders'), 'active', 'antrag');

        self::assertSame('antrag', ApiHarness::at($saved, 'id'));
        self::assertSame('antrag', ApiHarness::at($this->api->send('GET', '/workflows/antrag'), 'definition.id'));
    }

    public function testActiveNeedsAValidDefinitionDraftMayContainErrors(): void
    {
        $broken = ApiDefinitions::antragWith('erfassen', ['transitions' => [['to' => 'nirgends', 'event' => 'weiter']]]);

        $active = $this->save($broken);
        self::assertSame(422, $active->getStatusCode());
        self::assertSame('invalid_definition', ApiHarness::at($active, 'error.code'));
        self::assertSame('erfassen', ApiHarness::at($active, 'error.details.errors.0.step'));

        $draft = $this->save($broken, 'draft');
        self::assertSame(201, $draft->getStatusCode());
        self::assertNotSame([], ApiHarness::at($draft, 'validation'));

        $start = $this->api->send('POST', '/workflows/antrag/instances', ['context' => ['antragId' => 'A-1']]);
        self::assertSame(404, $start->getStatusCode(), 'Entwurf ist nicht startbar');
    }

    public function testMalformedRequestsAre422(): void
    {
        self::assertSame('validation_error', ApiHarness::at($this->api->send('POST', '/workflows/x', []), 'error.code'));
        self::assertSame(422, $this->save(ApiDefinitions::antrag(), 'aktiv')->getStatusCode());
        $noStart = ApiDefinitions::antrag();
        unset($noStart['startStep']);
        self::assertSame('invalid_definition', ApiHarness::at($this->save($noStart), 'error.code'));
    }

    public function testVersionsAndReadOnlyOldVersion(): void
    {
        $this->save(ApiDefinitions::antrag());
        $this->api->send('POST', '/workflows/antrag/instances', ['context' => ['antragId' => 'A-1']]);
        $changed = ApiDefinitions::antrag();
        $changed['name'] = 'Antrag prüfen (neu)';
        $second = $this->save($changed, 'draft');
        self::assertSame(2, ApiHarness::at($second, 'version'), 'Durchlauf auf v1 → neue Version');

        $versions = $this->api->send('GET', '/workflows/antrag/versions');
        self::assertSame([2, 1], ApiHarness::column($versions, 'versions', 'version'));
        self::assertSame(['latest', 'referenced'], ApiHarness::column($versions, 'versions', 'deleteBlockedBy'));
        self::assertSame(1, ApiHarness::at($versions, 'versions.1.runningInstances'));

        $old = $this->api->send('GET', '/workflows/antrag/versions/1');
        self::assertTrue(ApiHarness::at($old, 'readOnly'));
        self::assertSame('Antrag prüfen', ApiHarness::at($old, 'definition.name'));
        self::assertSame(404, $this->api->send('GET', '/workflows/antrag/versions/9')->getStatusCode());
        self::assertSame(422, $this->api->send('GET', '/workflows/antrag/versions/abc')->getStatusCode());
    }

    public function testDeleteVersionUsesTheUnifiedErrorFormat(): void
    {
        $this->save(ApiDefinitions::antrag());
        $this->save(ApiDefinitions::antrag());

        $latest = $this->api->send('DELETE', '/workflows/antrag/versions/2');
        self::assertSame(409, $latest->getStatusCode());
        self::assertSame('not_deletable', ApiHarness::at($latest, 'error.code'));
        self::assertSame('Die neueste Version bleibt immer erhalten.', ApiHarness::at($latest, 'error.message'));

        self::assertSame(204, $this->api->send('DELETE', '/workflows/antrag/versions/1')->getStatusCode());
        self::assertSame(404, $this->api->send('DELETE', '/workflows/antrag/versions/1')->getStatusCode());
    }

    public function testValidateOnlyReports(): void
    {
        $ok = $this->api->send('POST', '/workflows/validate', ['definition' => ApiDefinitions::antrag()]);
        self::assertSame(['valid' => true, 'errors' => []], ApiHarness::json($ok));

        $broken = ApiDefinitions::antragWith('erfassen', ['action' => 'gibts_nicht', 'type' => 'automatic']);
        $report = $this->api->send('POST', '/workflows/validate', ['definition' => $broken]);
        self::assertSame(200, $report->getStatusCode());
        self::assertFalse(ApiHarness::at($report, 'valid'));
        self::assertSame('erfassen', ApiHarness::at($report, 'errors.0.step'));

        self::assertFalse(ApiHarness::at($this->api->send('POST', '/workflows/validate', ['definition' => ['steps' => []]]), 'valid'));
        self::assertSame([], ApiHarness::at($this->api->send('GET', '/workflows'), 'definitions'), 'nichts gespeichert');
    }
}
