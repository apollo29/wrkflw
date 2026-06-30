<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

/**
 * Nebenlaeufigkeit: Das atomare Abholen faelliger Timer-Instanzen
 * (SELECT ... FOR UPDATE SKIP LOCKED + Status-Flip) darf eine Instanz
 * nicht doppelt herausgeben.
 */
#[CoversClass(PdoWorkflowRepository::class)]
#[Group('integration')]
final class ClaimDueInstancesTest extends IntegrationTestCase
{
    private PdoWorkflowRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PdoWorkflowRepository($this->pdo());
    }

    private function saveDue(string $id, \DateTimeImmutable $now): void
    {
        $this->repo->saveInstance(new WorkflowInstance(
            id: $id,
            definitionId: 'd',
            definitionVersion: 1,
            currentStep: 'wait',
            status: WorkflowInstance::WAITING_TIMER,
            wakeAt: $now->modify('-1 minute'),
        ));
    }

    public function testClaimFlipsStatusAndPreventsReclaim(): void
    {
        $now = new \DateTimeImmutable('2026-06-29 12:00:00');
        $this->saveDue('c1', $now);

        $first = $this->repo->claimDueInstances($now);
        self::assertSame(['c1'], array_map(static fn (WorkflowInstance $i): string => $i->id, $first));
        self::assertSame(
            [WorkflowInstance::RUNNING],
            array_map(static fn (WorkflowInstance $i): string => $i->status, $first),
        );

        // Zweiter Lauf findet die Instanz nicht mehr (Status ist running).
        self::assertSame([], $this->repo->claimDueInstances($now));
    }

    public function testClaimSkipsRowsLockedByAnotherTransaction(): void
    {
        $now = new \DateTimeImmutable('2026-06-29 12:00:00');
        $this->saveDue('locked', $now);

        // Zweite Verbindung sperrt die Zeile in einer offenen Transaktion.
        $other = $this->newConnection();
        $other->beginTransaction();
        $lock = $other->prepare('SELECT id FROM wf_instance WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => 'locked']);

        // Solange gesperrt: SKIP LOCKED ueberspringt die Zeile -> nichts geclaimt.
        $whileLocked = $this->repo->claimDueInstances($now);
        self::assertCount(0, $whileLocked);

        $other->commit(); // Sperre freigeben

        $claimed = $this->repo->claimDueInstances($now);
        self::assertSame(['locked'], array_map(static fn (WorkflowInstance $i): string => $i->id, $claimed));
    }
}
