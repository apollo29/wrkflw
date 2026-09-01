<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Http\ApiFactory;
use WorkflowEngine\Http\DefinitionController;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Alte Versionen einer Definition: wie viele Durchlaeufe haengen daran, und
 * darf man sie loeschen?
 *
 * Die Uebersicht sammelt mit jeder Aenderung eine weitere Version an. Nach
 * einem halben Jahr steht dort ein Dutzend Eintraege zu einem einzigen
 * Ablauf, und der aktuelle geht darin unter. Der Editor legt sie deshalb ins
 * Archiv — und dafuer muss er wissen, was noch daran haengt.
 *
 * ZWEI VERSCHIEDENE FRAGEN, die man leicht in eine wirft:
 *
 *   - Darf die Version INS ARCHIV? Ja, sobald kein Durchlauf mehr LAEUFT.
 *   - Darf sie GELOESCHT werden? Erst, wenn ueberhaupt kein Durchlauf mehr
 *     auf sie zeigt. Sonst waeren die abgeschlossenen Durchlaeufe nicht mehr
 *     lesbar — ihre Schritte stehen in der Definition, die dann fehlt.
 */
#[CoversClass(DefinitionController::class)]
final class DefinitionArchiveTest extends TestCase
{
    private InMemoryWorkflowRepository $repo;
    /** @var App<\Psr\Container\ContainerInterface|null> */
    private App $app;
    private WorkflowEngine $engine;

    /** @var array<string,mixed> */
    private const DEFINITION = [
        'startStep' => 'a',
        'steps' => [
            'a' => ['type' => 'interactive', 'transitions' => [['event' => 'go', 'to' => 'b']]],
            'b' => ['type' => 'automatic'],
        ],
    ];

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->engine = new WorkflowEngine($this->repo, new ActionRegistry(), new SymfonyExpressionEvaluator());
        $this->app = ApiFactory::create($this->engine, $this->repo);
    }

    public function testTheOverviewCountsInstancesPerVersion(): void
    {
        $this->speichern('flow');   // v1
        $this->speichern('flow');   // v2 — v1 ist jetzt alt

        // Ein Durchlauf auf v2, keiner auf v1.
        $this->engine->start('flow', []);

        $zeilen = $this->uebersicht();
        self::assertSame(0, $zeilen['flow:1']['instances']);
        self::assertSame(0, $zeilen['flow:1']['runningInstances']);
        self::assertSame(1, $zeilen['flow:2']['instances']);
        self::assertSame(1, $zeilen['flow:2']['runningInstances']);
    }

    public function testAFinishedRunStillCountsButNoLongerRuns(): void
    {
        $this->speichern('flow');
        $instanz = $this->engine->start('flow', []);
        // Der Schritt 'a' wartet auf 'go'; danach laeuft 'b' durch und der
        // Ablauf ist beendet.
        $this->engine->handleEvent($instanz->id, 'go');
        $this->speichern('flow');   // v2, damit v1 ueberhaupt archivierbar waere

        $zeilen = $this->uebersicht();
        self::assertSame(1, $zeilen['flow:1']['instances'], 'Der abgeschlossene Durchlauf zaehlt weiter.');
        self::assertSame(0, $zeilen['flow:1']['runningInstances'], 'Laufen tut er nicht mehr.');
    }

    public function testAnOldVersionWithoutAnyRunCanBeDeleted(): void
    {
        $this->speichern('flow');
        $this->speichern('flow');

        $antwort = $this->send('DELETE', '/workflows/flow/versions/1');
        self::assertSame(204, $antwort->getStatusCode());
        self::assertArrayNotHasKey('flow:1', $this->uebersicht());
        self::assertArrayHasKey('flow:2', $this->uebersicht());
    }

    public function testTheNewestVersionIsNeverDeleted(): void
    {
        $this->speichern('flow');
        $this->speichern('flow');

        $antwort = $this->send('DELETE', '/workflows/flow/versions/2');
        self::assertSame(409, $antwort->getStatusCode());
        self::assertArrayHasKey('flow:2', $this->uebersicht(), 'Die aktuelle Version wurde geloescht.');
    }

    public function testAVersionWithAFinishedRunIsKept(): void
    {
        $this->speichern('flow');
        $instanz = $this->engine->start('flow', []);
        $this->engine->handleEvent($instanz->id, 'go');
        $this->speichern('flow');

        // Der Durchlauf ist beendet — die Version darf trotzdem nicht weg,
        // sonst laesst sich sein Verlauf nicht mehr aufloesen.
        $antwort = $this->send('DELETE', '/workflows/flow/versions/1');
        self::assertSame(409, $antwort->getStatusCode());
        self::assertArrayHasKey('flow:1', $this->uebersicht());
    }

    public function testDeletingSomethingThatIsNotThereIsRefusedToo(): void
    {
        $this->speichern('flow');

        self::assertSame(409, $this->send('DELETE', '/workflows/flow/versions/7')->getStatusCode());
        self::assertSame(409, $this->send('DELETE', '/workflows/gibtsnicht/versions/1')->getStatusCode());
    }

    // ---------------------------------------------------------------- Helpers

    private function speichern(string $id): void
    {
        $antwort = $this->send('POST', "/workflows/{$id}", [
            'name' => 'Flow',
            'definition' => self::DEFINITION,
        ]);
        self::assertContains($antwort->getStatusCode(), [200, 201], 'Speichern fehlgeschlagen.');
    }

    /** @return array<string, array<string,mixed>> je Zeile unter "id:version" */
    private function uebersicht(): array
    {
        $body = json_decode((string) $this->send('GET', '/workflows')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $out = [];
        foreach ($body['definitions'] as $zeile) {
            $out["{$zeile['id']}:{$zeile['version']}"] = $zeile;
        }

        return $out;
    }

    /** @param array<string,mixed>|null $body */
    private function send(string $method, string $path, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body)->withHeader('Content-Type', 'application/json');
        }

        return $this->app->handle($request);
    }
}
