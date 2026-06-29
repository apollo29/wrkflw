<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

#[CoversClass(PdoWorkflowRepository::class)]
#[Group('integration')]
final class PdoWorkflowRepositoryTest extends IntegrationTestCase
{
    private PdoWorkflowRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PdoWorkflowRepository($this->pdo());
    }

    public function testSaveAndLoadInstanceRoundtrip(): void
    {
        $instance = new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'onboarding',
            definitionVersion: 2,
            currentStep: 'await_profile',
            status: WorkflowInstance::WAITING_EVENT,
            context: [
                'name' => 'Mara',
                'vip' => true,
                'prefs' => ['lang' => 'de', 'tags' => [1, 2, 3]],
            ],
            wakeAt: null,
            subjectType: 'user',
            subjectId: '42',
            lastError: null,
        );

        $this->repo->saveInstance($instance);
        $loaded = $this->repo->findInstance('inst-1');

        self::assertNotNull($loaded);
        self::assertSame('inst-1', $loaded->id);
        self::assertSame('onboarding', $loaded->definitionId);
        self::assertSame(2, $loaded->definitionVersion);
        self::assertSame('await_profile', $loaded->currentStep);
        self::assertSame(WorkflowInstance::WAITING_EVENT, $loaded->status);
        self::assertSame('user', $loaded->subjectType);
        self::assertSame('42', $loaded->subjectId);
        self::assertEquals($instance->context, $loaded->context);
    }

    public function testSaveInstanceUpdatesExisting(): void
    {
        $instance = new WorkflowInstance(
            id: 'inst-2',
            definitionId: 'onboarding',
            definitionVersion: 1,
            currentStep: 'send_welcome',
            status: WorkflowInstance::RUNNING,
        );
        $this->repo->saveInstance($instance);

        $instance->currentStep = 'done';
        $instance->status = WorkflowInstance::COMPLETED;
        $instance->set('result', 'ok');
        $this->repo->saveInstance($instance);

        $loaded = $this->repo->findInstance('inst-2');
        self::assertNotNull($loaded);
        self::assertSame('done', $loaded->currentStep);
        self::assertSame(WorkflowInstance::COMPLETED, $loaded->status);
        self::assertSame('ok', $loaded->context['result']);
    }

    public function testFindInstanceReturnsNullWhenMissing(): void
    {
        self::assertNull($this->repo->findInstance('does-not-exist'));
    }

    public function testFindDueInstancesReturnsOnlyDueTimers(): void
    {
        $now = new \DateTimeImmutable('2026-06-29 12:00:00');

        $due = new WorkflowInstance(
            id: 'timer-due',
            definitionId: 'd',
            definitionVersion: 1,
            currentStep: 'wait',
            status: WorkflowInstance::WAITING_TIMER,
            wakeAt: $now->modify('-1 minute'),
        );
        $future = new WorkflowInstance(
            id: 'timer-future',
            definitionId: 'd',
            definitionVersion: 1,
            currentStep: 'wait',
            status: WorkflowInstance::WAITING_TIMER,
            wakeAt: $now->modify('+1 hour'),
        );
        $running = new WorkflowInstance(
            id: 'running',
            definitionId: 'd',
            definitionVersion: 1,
            currentStep: 'go',
            status: WorkflowInstance::RUNNING,
        );

        $this->repo->saveInstance($due);
        $this->repo->saveInstance($future);
        $this->repo->saveInstance($running);

        $result = $this->repo->findDueInstances($now);

        $ids = array_map(static fn (WorkflowInstance $i): string => $i->id, $result);
        self::assertSame(['timer-due'], $ids);
    }

    public function testLogHistoryWritesRow(): void
    {
        // FK: erst eine Instanz, dann History.
        $this->repo->saveInstance(new WorkflowInstance(
            id: 'inst-hist',
            definitionId: 'd',
            definitionVersion: 1,
            currentStep: 'send_welcome',
            status: WorkflowInstance::RUNNING,
        ));

        $this->repo->logHistory('inst-hist', 'action', 'send_welcome', ['action' => 'send_email']);
        $this->repo->logHistory('inst-hist', 'transition', 'send_welcome', ['to' => 'await_profile']);

        $stmt = $this->pdo()->prepare(
            'SELECT kind, step, detail FROM wf_history WHERE instance_id = :id ORDER BY id ASC'
        );
        $stmt->execute([':id' => 'inst-hist']);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(2, $rows);
        self::assertIsArray($rows[0]);
        self::assertSame('action', $rows[0]['kind']);
        self::assertSame('send_welcome', $rows[0]['step']);
        self::assertIsString($rows[0]['detail']);
        self::assertStringContainsString('send_email', $rows[0]['detail']);
    }

    public function testFindDefinitionReturnsLatestActiveVersion(): void
    {
        $this->seedDefinition('onboarding', 1, '{"startStep":"a","steps":{"a":{"type":"automatic"}}}', true);
        $this->seedDefinition('onboarding', 2, '{"startStep":"b","steps":{"b":{"type":"automatic"}}}', true);
        $this->seedDefinition('onboarding', 3, '{"startStep":"c","steps":{"c":{"type":"automatic"}}}', false);

        $def = $this->repo->findDefinition('onboarding');
        self::assertSame(2, $def->version);
        self::assertSame('b', $def->startStep);

        $explicit = $this->repo->findDefinition('onboarding', 1);
        self::assertSame(1, $explicit->version);
        self::assertSame('a', $explicit->startStep);
    }

    private function seedDefinition(string $id, int $version, string $json, bool $active): void
    {
        $stmt = $this->pdo()->prepare(
            'INSERT INTO wf_definition (id, version, name, definition, active)
             VALUES (:id, :v, :name, :def, :active)'
        );
        $stmt->execute([
            ':id' => $id,
            ':v' => $version,
            ':name' => $id,
            ':def' => $json,
            ':active' => $active ? 1 : 0,
        ]);
    }
}
