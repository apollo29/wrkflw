<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionStatus;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

/** Hintergrundlauf: fällige Durchläufe belegen (ADR 4, Q-02, Q-03). */
final class ClaimDueInstancesTest extends IntegrationTestCase
{
    private FrozenClock $clock;

    private PdoWorkflowRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-10-31 09:00:00');
        $this->repo = new PdoWorkflowRepository($this->pdo(), $this->clock);
        $this->repo->save(DefinitionParser::fromArray(['id' => 'w', 'startStep' => 's', 'steps' => ['s' => []]]), DefinitionStatus::Active);
    }

    private function save(string $suffix, InstanceStatus $status, ?string $wake = null): void
    {
        $this->repo->saveInstance(new WorkflowInstance(
            "00000000-0000-4000-8000-{$suffix}",
            'w',
            1,
            's',
            $status,
            [],
            $wake === null ? null : $this->clock->now()->modify($wake),
        ));
    }

    public function testClaimsDueTimersAndStaleRunsButNotFutureOnes(): void
    {
        $this->save('000000000001', InstanceStatus::WaitingTimer, '-1 minute');
        $this->save('000000000002', InstanceStatus::WaitingTimer, '+1 minute');
        $this->save('000000000003', InstanceStatus::WaitingEvent);
        $this->save('000000000004', InstanceStatus::Running);

        self::assertSame(['00000000-0000-4000-8000-000000000001'], $this->ids($this->repo->claimDue($this->clock->now(), 10, 300)));
        self::assertSame(InstanceStatus::Running, $this->repo->findInstance('00000000-0000-4000-8000-000000000001')?->status);

        // Die belegte Instanz ist bis zum Ablauf der Lease nicht noch einmal fällig.
        self::assertSame([], $this->repo->claimDue($this->clock->now(), 10, 300));

        $this->clock->advance('+6 minutes');
        self::assertSame(
            ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000004'],
            $this->ids($this->repo->claimDue($this->clock->now(), 10, 300)),
        );
    }

    public function testParallelWorkersNeverGetTheSameInstance(): void
    {
        $this->save('000000000001', InstanceStatus::WaitingTimer, '-1 minute');
        $this->save('000000000002', InstanceStatus::WaitingTimer, '-1 minute');

        $other = $this->newConnection();
        $other->beginTransaction();
        $other->query("SELECT id FROM wf_instance WHERE id = '00000000-0000-4000-8000-000000000001' FOR UPDATE");

        self::assertSame(['00000000-0000-4000-8000-000000000002'], $this->ids($this->repo->claimDue($this->clock->now(), 10, 300)));
        $other->rollBack();
    }

    /**
     * @param list<WorkflowInstance> $instances
     *
     * @return list<string>
     */
    private function ids(array $instances): array
    {
        $ids = array_map(static fn (WorkflowInstance $i): string => $i->id, $instances);
        sort($ids);

        return $ids;
    }
}
