<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\Field;
use WorkflowEngine\Persistence\InMemoryPersistence;

final class InMemoryPersistenceTest extends TestCase
{
    private DataModel $model;

    private InMemoryPersistence $storage;

    protected function setUp(): void
    {
        $this->model = new DataModel(
            name: 'person',
            label: 'Personen',
            idField: 'id',
            fields: [Field::text('id', 'ID', actual: 'nr'), Field::text('mail', 'E-Mail', actual: 'kontakt_mail')->asWritable(), Field::date('frist', 'Frist')],
            source: 'personen',
            subset: ['aktiv' => 1],
            canUpdate: true,
            canCreate: true,
        );
        $this->storage = new InMemoryPersistence();
        $this->storage->seed('personen', [
            ['nr' => '1', 'kontakt_mail' => 'a@example.org', 'frist' => '2026-11-10', 'aktiv' => 1],
            ['nr' => '2', 'kontakt_mail' => '', 'frist' => null, 'aktiv' => 1],
            ['nr' => '3', 'kontakt_mail' => 'c@example.org', 'frist' => '2026-11-01', 'aktiv' => 0],
        ], idColumn: 'nr');
    }

    public function testMapsStorageNamesToFieldNames(): void
    {
        self::assertSame(['id' => '1', 'mail' => 'a@example.org', 'frist' => '2026-11-10'], $this->storage->load($this->model, '1'));
        self::assertNull($this->storage->load($this->model, '3'));
    }

    public function testConditions(): void
    {
        $ids = fn (Condition ...$c): array => array_column($this->storage->find($this->model, array_values($c), 10), 'id');

        self::assertSame(['2'], $ids(new Condition('mail', ConditionOperator::Empty)));
        self::assertSame(['1'], $ids(new Condition('mail', ConditionOperator::NotEmpty)));
        self::assertSame(['1'], $ids(new Condition('frist', ConditionOperator::Le, '2026-11-30')));
        self::assertSame(['1', '2'], $ids(new Condition('mail', ConditionOperator::Ne, 'x')));
    }

    public function testUpdateAndCreateStayInsideTheSubset(): void
    {
        $origin = new ChangeOrigin('w', 1, 'i', 's');

        self::assertFalse($this->storage->update($this->model, '3', ['mail' => 'neu@example.org'], $origin));
        self::assertTrue($this->storage->update($this->model, '1', ['mail' => 'neu@example.org'], $origin));
        self::assertSame('neu@example.org', $this->storage->row('personen', '1', 'nr')['kontakt_mail'] ?? null);

        $id = $this->storage->create($this->model, null, ['mail' => 'z@example.org'], $origin);
        self::assertSame(['nr' => $id, 'kontakt_mail' => 'z@example.org', 'aktiv' => 1], $this->storage->row('personen', $id, 'nr'));
    }
}
