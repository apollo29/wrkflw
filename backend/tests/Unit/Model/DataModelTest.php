<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\Field;
use WorkflowEngine\Model\FieldType;
use WorkflowEngine\Model\Record;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Tests\Support\Models;

final class DataModelTest extends TestCase
{
    public function testTheBuilderDescribesTheExampleModel(): void
    {
        $model = Models::antrag();

        self::assertSame('antrag', $model->name);
        self::assertSame('Anträge', $model->label);
        self::assertSame('id', $model->idField);
        self::assertSame('antrag', $model->sourceName());
        self::assertSame(['archiviert' => 0], $model->subset);
        self::assertTrue($model->canUpdate);
        self::assertFalse($model->canCreate);
        self::assertSame(['status', 'geprueft_am'], array_keys($model->writableFields()));
        self::assertSame(FieldType::Number, $model->field('betrag')->type);
        self::assertTrue($model->hasField('mail'));
        self::assertFalse($model->hasField('iban'), 'Nicht beschriebene Felder gibt es nicht (F-116).');
    }

    public function testUnknownFieldsArePermanentErrors(): void
    {
        $this->expectException(DataModelException::class);
        $this->expectExceptionMessage("Das Datenmodell 'antrag' hat kein Feld 'iban'.");
        Models::antrag()->field('iban');
    }

    public function testWritableFieldsRequireAWriteOperation(): void
    {
        $this->expectException(DataModelException::class);
        new DataModel('x', 'X', 'id', [Field::text('id', 'ID'), Field::text('a', 'A')->asWritable()]);
    }

    public function testIdFieldMustBeDescribedAndNamesMustBeSimple(): void
    {
        foreach ([
            static fn () => new DataModel('x', 'X', 'nr', [Field::text('id', 'ID')]),
            static fn () => new DataModel('x y', 'X', 'id', [Field::text('id', 'ID')]),
            static fn () => new DataModel('x', 'X', 'id', [Field::text('id', 'ID'), Field::text('a;drop', 'A')]),
            static fn () => new DataModel('x', 'X', 'id', [Field::text('id', 'ID')], subset: ['a b' => 1]),
            static fn () => new DataModel('x', 'X', 'id', [Field::text('id', 'ID'), Field::text('a', 'A'), Field::text('a', 'B')]),
        ] as $i => $make) {
            try {
                $make();
                self::fail("Fall {$i} nicht abgelehnt.");
            } catch (DataModelException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRecordsTrackChangesWithoutTouchingTheOriginal(): void
    {
        $loaded = Record::loaded('antrag', 'A-1', ['id' => 'A-1', 'status' => 'offen', 'betrag' => 1250]);
        $changed = $loaded->with('status', 'freigegeben')->with('betrag', 1250)->with('geprueft_am', null);

        self::assertSame([], $loaded->changes());
        self::assertSame('offen', $loaded->get('status'));
        self::assertSame(['status' => 'freigegeben', 'geprueft_am' => null], $changed->changes());
        self::assertFalse($changed->isNew);
        self::assertTrue(Record::create('antrag', 'A-2')->isNew);
    }

    public function testRegistryMergesSourcesAndRejectsDuplicateNames(): void
    {
        $other = DataModel::define('diplom', 'Diplome')->text('titel', 'Titel')->build();
        $registry = new DataModelRegistry([
            new StaticDataModelSource([Models::antrag()]),
            new StaticDataModelSource([$other]),
        ]);

        self::assertSame(['antrag', 'diplom'], array_keys($registry->all()));
        self::assertSame($other, $registry->get('diplom'));
        self::assertFalse($registry->has('x'));

        $clash = new DataModelRegistry([new StaticDataModelSource([Models::antrag()]), new StaticDataModelSource([Models::antrag()])]);
        $this->expectException(DataModelException::class);
        $this->expectExceptionMessage("Datenmodell 'antrag' ist mehrfach definiert.");
        $clash->all();
    }
}
