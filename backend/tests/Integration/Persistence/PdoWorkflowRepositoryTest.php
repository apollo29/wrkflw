<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionStatus;
use WorkflowEngine\Definition\VersionDeleteBlock;
use WorkflowEngine\Definition\WorkflowDefinition;
use WorkflowEngine\Exception\NotFoundException;
use WorkflowEngine\Instance\HistoryKind;
use WorkflowEngine\Instance\InstanceFilter;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

final class PdoWorkflowRepositoryTest extends IntegrationTestCase
{
    private FrozenClock $clock;

    private PdoWorkflowRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-10-31 09:00:00');
        $this->repo = new PdoWorkflowRepository($this->pdo(), $this->clock);
    }

    private static function def(string $finalStep = 'ende', string $name = 'Antrag prüfen'): WorkflowDefinition
    {
        return DefinitionParser::fromArray([
            'id' => 'antrag',
            'name' => $name,
            'startStep' => 'start',
            'steps' => [
                'start' => ['type' => 'interactive', 'transitions' => [['to' => $finalStep, 'event' => 'submit']]],
                $finalStep => ['action' => 'check_data', 'config' => ['entity' => 'antrag']],
            ],
        ]);
    }

    private function instance(int $version, InstanceStatus $status, string $id = ''): WorkflowInstance
    {
        $instance = new WorkflowInstance(
            $id !== '' ? $id : sprintf('00000000-0000-4000-8000-%012d', random_int(1, 999_999_999)),
            'antrag',
            $version,
            'start',
            $status,
            ['antrag_id' => 'A-1', 'name' => 'Müller'],
            subjectType: 'antrag',
            subjectId: 'A-1',
        );
        $this->repo->saveInstance($instance);

        return $instance;
    }

    public function testActiveSavesCreateVersionsAndOnlyTheNewestActiveStarts(): void
    {
        $v1 = $this->repo->save(self::def(), DefinitionStatus::Active);
        $this->clock->advance('+1 hour');
        $v2 = $this->repo->save(self::def('fertig'), DefinitionStatus::Active);

        self::assertSame([1, true], [$v1->version, $v1->newVersion]);
        self::assertSame([2, true], [$v2->version, $v2->newVersion]);
        self::assertSame(2, $this->repo->findDefinition('antrag')->version);
        self::assertTrue($this->repo->findDefinition('antrag', 1)->hasStep('ende'));
        self::assertTrue($this->repo->findDefinition('antrag')->hasStep('fertig'));
    }

    public function testDraftOverwritesTheCurrentVersionWithoutRuns(): void
    {
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $this->clock->advance('+1 hour');

        $draft = $this->repo->save(self::def('fertig', 'Neu'), DefinitionStatus::Draft);

        self::assertSame([1, false, DefinitionStatus::Draft], [$draft->version, $draft->newVersion, $draft->status]);
        self::assertStringContainsString('fertig', (string) $this->repo->findJson('antrag'));
        $versions = $this->repo->versions('antrag');
        self::assertCount(1, $versions);
        self::assertSame('Neu', $versions[0]->name);
        self::assertEquals($this->clock->now(), $versions[0]->updatedAt);

        $this->expectException(NotFoundException::class);
        $this->repo->findDefinition('antrag');
    }

    public function testDraftCreatesANewVersionWhenRunsReferenceTheCurrentOne(): void
    {
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $this->instance(1, InstanceStatus::Completed);

        $draft = $this->repo->save(self::def('fertig'), DefinitionStatus::Inactive);

        self::assertSame([2, true], [$draft->version, $draft->newVersion]);
        self::assertTrue($this->repo->findDefinition('antrag', 1)->hasStep('ende'), 'Version 1 bleibt unverändert.');
        self::assertSame([2, 1], array_map(static fn ($v): int => $v->version, $this->repo->versions('antrag')));
    }

    public function testListShowsOneRowPerWorkflowWithCountsAndStructure(): void
    {
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $this->instance(1, InstanceStatus::Completed);
        $this->repo->save(self::def('fertig'), DefinitionStatus::Active);
        $this->instance(2, InstanceStatus::WaitingEvent);
        $this->instance(2, InstanceStatus::Cancelled);
        $this->instance(2, InstanceStatus::WaitingTimer);

        $list = $this->repo->listDefinitions();

        self::assertCount(1, $list);
        $row = $list[0];
        self::assertSame(['antrag', 'Antrag prüfen', 2, DefinitionStatus::Active], [$row->id, $row->name, $row->version, $row->status]);
        self::assertSame([2, 4, 1], [$row->runningInstances, $row->totalInstances, $row->olderVersions]);
        self::assertNotNull($row->structure);
        self::assertSame('start', $row->structure['startStep']);
        self::assertSame(['interactive', 'check'], array_column($row->structure['steps'], 'kind'));
    }

    public function testVersionsCanOnlyBeDeletedWithoutRunsAndNeverTheNewest(): void
    {
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $this->instance(1, InstanceStatus::Completed);

        $versions = $this->repo->versions('antrag');
        self::assertSame(
            [VersionDeleteBlock::Latest, null, VersionDeleteBlock::Referenced],
            array_map(static fn ($v): ?VersionDeleteBlock => $v->deleteBlockedBy, $versions),
        );

        self::assertSame(VersionDeleteBlock::Latest, $this->repo->deleteVersion('antrag', 3));
        self::assertSame(VersionDeleteBlock::Referenced, $this->repo->deleteVersion('antrag', 1));
        self::assertSame(VersionDeleteBlock::NotFound, $this->repo->deleteVersion('antrag', 9));
        self::assertNull($this->repo->deleteVersion('antrag', 2));
        self::assertSame([3, 1], array_map(static fn ($v): int => $v->version, $this->repo->versions('antrag')));
    }

    public function testInstancesRoundTripIncludingCancelledAndTime(): void
    {
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $instance = $this->instance(1, InstanceStatus::WaitingTimer);
        $instance->wakeAt = $this->clock->now()->modify('+1 day');
        $instance->attempts = 2;
        $instance->lastError = 'Zeitüberschreitung';
        $this->repo->saveInstance($instance);

        self::assertEquals($instance, $this->repo->findInstance($instance->id));

        $instance->status = InstanceStatus::Cancelled;
        $instance->wakeAt = null;
        $this->repo->saveInstance($instance);
        self::assertSame(InstanceStatus::Cancelled, $this->repo->findInstance($instance->id)?->status);
        self::assertNull($this->repo->findInstance('nope'));
    }

    public function testHistoryIsOrderedAndTimedByTheClock(): void
    {
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $instance = $this->instance(1, InstanceStatus::Running);

        $this->repo->logHistory($instance->id, HistoryKind::Start, 'start', ['contextKeys' => ['antrag_id']]);
        $this->clock->advance('+5 minutes');
        $this->repo->logHistory($instance->id, HistoryKind::Event, 'start', ['event' => 'submit', 'payloadKeys' => []]);

        $history = $this->repo->findHistory($instance->id);
        self::assertSame([HistoryKind::Start, HistoryKind::Event], array_map(static fn ($e): HistoryKind => $e->kind, $history));
        self::assertSame(['contextKeys' => ['antrag_id']], $history[0]->detail);
        self::assertEquals($this->clock->now(), $history[1]->at);
    }

    public function testInstanceListAndCounts(): void
    {
        $this->repo->save(self::def(), DefinitionStatus::Active);
        $failed = $this->instance(1, InstanceStatus::Failed, '00000000-0000-4000-8000-000000000001');
        $this->clock->advance('+1 minute');
        $this->instance(1, InstanceStatus::WaitingEvent, '00000000-0000-4000-8000-000000000002');

        $all = $this->repo->listInstances(new InstanceFilter());
        $onlyFailed = $this->repo->listInstances(new InstanceFilter(status: InstanceStatus::Failed));
        $page = $this->repo->listInstances(new InstanceFilter(limit: 1, offset: 1));

        self::assertSame(['00000000-0000-4000-8000-000000000002', $failed->id], array_map(static fn ($s): string => $s->id, $all));
        self::assertSame('Antrag prüfen', $all[0]->definitionName);
        self::assertSame([$failed->id], array_map(static fn ($s): string => $s->id, $onlyFailed));
        self::assertSame([$failed->id], array_map(static fn ($s): string => $s->id, $page));

        $counts = $this->repo->countByStatus();
        self::assertSame(1, $counts['failed']);
        self::assertSame(1, $counts['waiting_event']);
        self::assertSame(0, $counts['cancelled']);
    }

    public function testTemplateUsageFindsMailAndPageTemplates(): void
    {
        $this->repo->save(DefinitionParser::fromArray([
            'id' => 'mit-vorlagen',
            'startStep' => 'seite',
            'steps' => [
                'seite' => ['type' => 'interactive', 'ui' => ['template' => 'info'], 'transitions' => [['to' => 'mail', 'event' => 'submit']]],
                'mail' => ['action' => 'send_email', 'config' => ['templateId' => 'antrag-ok', 'to' => 'x@example.org']],
            ],
        ]), DefinitionStatus::Active);

        $usage = $this->repo->templateUsage('antrag-ok');

        self::assertCount(1, $usage);
        self::assertSame(['mit-vorlagen', 1, 'mail'], [$usage[0]->definitionId, $usage[0]->version, $usage[0]->step]);
        self::assertSame('seite', $this->repo->templateUsage('info')[0]->step ?? null);
    }
}
