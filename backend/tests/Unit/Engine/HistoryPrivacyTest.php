<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\InMemoryWorkflowRepository;

/**
 * Die History haelt fest, WAS passiert ist — nicht, mit welchen Daten.
 *
 * Sie ist ein dauerhaftes Protokoll und wird in der Admin-Oberflaeche angezeigt.
 * Ein Workflow traegt aber Personendaten durch den Kontext: im
 * Trainer-Onboarding Namen und Bemerkungen, in der Warteliste Adressen von
 * Eltern. Die landeten bisher an drei Stellen im Klartext in der History —
 * beim Start der ganze Kontext, bei jeder Action ihr Ergebnis (SendEmailAction
 * liefert `lastEmailTo`) und bei jedem Event der Payload.
 *
 * Protokolliert werden deshalb nur noch die SCHLUESSELNAMEN. Damit bleibt
 * nachvollziehbar, welche Felder gesetzt wurden, ohne die Werte aufzubewahren.
 */
#[CoversClass(WorkflowEngine::class)]
final class HistoryPrivacyTest extends TestCase
{
    private const SENTINEL = 'streng-geheim-079-123-45-67';

    private InMemoryWorkflowRepository $repo;
    private ActionRegistry $actions;

    protected function setUp(): void
    {
        $this->repo = new InMemoryWorkflowRepository();
        $this->actions = new ActionRegistry();
        $this->actions->register('echo', $this->echoAction());

        $this->repo->addDefinition(WorkflowDefinition::fromArray([
            'id' => 'privacy-flow',
            'startStep' => 'wait',
            'steps' => [
                'wait' => [
                    'type' => 'interactive',
                    'ui' => ['fields' => [['name' => 'bemerkung']]],
                    'transitions' => [['event' => 'submit', 'to' => 'act']],
                ],
                'act' => ['type' => 'automatic', 'action' => 'echo', 'transitions' => [['to' => 'done']]],
                'done' => ['type' => 'automatic'],
            ],
        ]));
    }

    private function engine(): WorkflowEngine
    {
        return new WorkflowEngine($this->repo, $this->actions, new SymfonyExpressionEvaluator());
    }

    public function testStartLogsContextKeysWithoutValues(): void
    {
        $this->engine()->start('privacy-flow', ['name' => self::SENTINEL, 'plan' => 'pro']);

        $detail = $this->detailOf('start');
        self::assertSame(['name', 'plan'], $detail['contextKeys']);
        self::assertArrayNotHasKey('context', $detail);
    }

    public function testEventLogsPayloadKeysWithoutValues(): void
    {
        $engine = $this->engine();
        $instance = $engine->start('privacy-flow', []);
        $engine->handleEvent($instance->id, 'submit', ['bemerkung' => self::SENTINEL]);

        $detail = $this->detailOf('event');
        self::assertSame('submit', $detail['event']);
        self::assertSame(['bemerkung'], $detail['payloadKeys']);
        self::assertArrayNotHasKey('payload', $detail);
    }

    public function testActionLogsResultKeysWithoutValues(): void
    {
        $engine = $this->engine();
        $instance = $engine->start('privacy-flow', []);
        $engine->handleEvent($instance->id, 'submit', ['bemerkung' => self::SENTINEL]);

        $detail = $this->detailOf('action');
        self::assertSame('echo', $detail['action']);
        self::assertSame(['lastEmailTo'], $detail['resultKeys']);
        self::assertArrayNotHasKey('result', $detail);
    }

    /**
     * Die eigentliche Zusicherung: der Wert taucht NIRGENDS in der History auf.
     *
     * Bewusst ueber alle Eintraege statt nur ueber die drei bekannten — kommt
     * spaeter ein weiterer Kanal dazu, faellt er hier auf.
     */
    public function testNoHistoryEntryContainsAValue(): void
    {
        $engine = $this->engine();
        $instance = $engine->start('privacy-flow', ['name' => self::SENTINEL]);
        $engine->handleEvent($instance->id, 'submit', ['bemerkung' => self::SENTINEL]);

        $serialized = (string) json_encode($this->repo->history());

        self::assertStringNotContainsString(self::SENTINEL, $serialized);
        // Gegenprobe im selben Test: die Schluessel sind sehr wohl da, der Scan
        // prueft also nicht gegen eine leere History.
        self::assertStringContainsString('bemerkung', $serialized);
        self::assertStringContainsString('contextKeys', $serialized);
    }

    /** @return array<string, mixed> */
    private function detailOf(string $kind): array
    {
        foreach ($this->repo->history() as $entry) {
            if ($entry['kind'] === $kind) {
                return $entry['detail'];
            }
        }

        self::fail("Kein History-Eintrag der Art '{$kind}'.");
    }

    /** Liefert einen Wert zurueck, wie es SendEmailAction mit lastEmailTo tut. */
    private function echoAction(): ActionInterface
    {
        return new class () implements ActionInterface {
            public function execute(WorkflowInstance $instance, Step $step): array
            {
                return ['lastEmailTo' => HistoryPrivacyTest::sentinel()];
            }
        };
    }

    public static function sentinel(): string
    {
        return self::SENTINEL;
    }
}
