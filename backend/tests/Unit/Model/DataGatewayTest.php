<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Model\Change;
use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataCatalog;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\DataValidationException;
use WorkflowEngine\Model\InvalidFieldValueException;
use WorkflowEngine\Model\Record;
use WorkflowEngine\Model\RuleInterface;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Persistence\InMemoryPersistence;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Tests\Support\Models;

final class DataGatewayTest extends TestCase
{
    private InMemoryPersistence $storage;

    private ChangeOrigin $origin;

    protected function setUp(): void
    {
        $this->storage = new InMemoryPersistence();
        $this->storage->seed('antrag', [
            Models::antragRow('A-1'),
            Models::antragRow('A-2', betrag: 500),
            Models::antragRow('A-3', archiviert: 1),
        ]);
        $this->origin = new ChangeOrigin('antrag-pruefung', 3, 'inst-1', 'speichern');
    }

    private function gateway(DataModel ...$models): DataGateway
    {
        return new DataGateway(
            new DataModelRegistry([new StaticDataModelSource($models === [] ? [Models::antrag()] : array_values($models))]),
            new PersistenceRegistry(['db' => $this->storage]),
        );
    }

    public function testLoadReturnsTypedValuesOrNull(): void
    {
        $record = $this->gateway()->load('antrag', 'A-1');

        self::assertNotNull($record);
        self::assertSame('A-1', $record->id);
        self::assertSame(['id' => 'A-1', 'status' => 'offen', 'betrag' => 1250, 'mail' => 'p@example.org', 'geprueft_am' => null], $record->values);
        self::assertNull($this->gateway()->load('antrag', 'A-9'), 'Nicht gefunden ist eine Antwort (F-124).');
        self::assertNull($this->gateway()->load('antrag', 'A-3'), 'Ausserhalb der Teilmenge gibt es nichts (F-112).');
    }

    public function testFindCombinesConditionsAndRespectsTheLimit(): void
    {
        $gateway = $this->gateway();

        $all = $gateway->find('antrag', [new Condition('status', ConditionOperator::Eq, 'offen')]);
        $big = $gateway->find('antrag', [
            new Condition('status', ConditionOperator::Eq, 'offen'),
            new Condition('betrag', ConditionOperator::Gt, 1000),
        ]);
        $one = $gateway->find('antrag', [], limit: 1);

        self::assertSame(['A-1', 'A-2'], array_map(static fn (Record $r): string => $r->id, $all));
        self::assertSame(['A-1'], array_map(static fn (Record $r): string => $r->id, $big));
        self::assertCount(1, $one);
    }

    public function testUpdateWritesOnlyChangedFieldsWithOrigin(): void
    {
        $gateway = $this->gateway();
        $record = $gateway->load('antrag', 'A-1');
        self::assertNotNull($record);

        $result = $gateway->save($record->with('status', 'freigegeben')->with('geprueft_am', '31.10.2026'), $this->origin);

        self::assertTrue($result->written);
        self::assertSame(['status', 'geprueft_am'], $result->changedFields);
        self::assertSame(['status' => 'freigegeben', 'geprueft_am' => '2026-10-31'], $this->storage->lastWrite);
        self::assertSame($this->origin, $this->storage->lastOrigin);
        self::assertSame('freigegeben', $this->storage->row('antrag', 'A-1')['status'] ?? null);
    }

    public function testNothingChangedIsAResultNotAnError(): void
    {
        $gateway = $this->gateway();
        $record = $gateway->load('antrag', 'A-1');
        self::assertNotNull($record);

        $result = $gateway->save($record->with('status', 'offen')->with('betrag', '1250'), $this->origin);

        self::assertFalse($result->written);
        self::assertSame([], $result->changedFields);
        self::assertNull($this->storage->lastWrite);
    }

    public function testFieldsWithoutReleaseCannotBeWritten(): void
    {
        $gateway = $this->gateway();
        $record = $gateway->load('antrag', 'A-1');
        self::assertNotNull($record);

        $this->expectException(DataModelException::class);
        $this->expectExceptionMessage("Feld 'betrag' im Datenmodell 'antrag' ist nicht schreibbar.");
        $gateway->save($record->with('betrag', 1), $this->origin);
    }

    public function testCreateNeedsItsOwnRelease(): void
    {
        try {
            $this->gateway()->save(Record::create('antrag', 'A-5')->with('status', 'neu'), $this->origin);
            self::fail('Anlegen ohne Freigabe.');
        } catch (DataModelException $e) {
            self::assertStringContainsString('Anlegen', $e->getMessage());
        }

        $creatable = DataModel::define('antrag', 'Anträge')
            ->text('status', 'Status')->writable()
            ->subset(['archiviert' => 0])
            ->creatable()
            ->build();
        $result = $this->gateway($creatable)->save(Record::create('antrag', 'A-5')->with('status', 'neu'), $this->origin);

        self::assertTrue($result->written);
        self::assertSame('A-5', $result->id);
        self::assertSame(['id' => 'A-5', 'status' => 'neu', 'archiviert' => 0], $this->storage->row('antrag', 'A-5'));
    }

    public function testInvalidValuesAreRejectedBeforeWriting(): void
    {
        $gateway = $this->gateway();
        $record = $gateway->load('antrag', 'A-1');
        self::assertNotNull($record);

        try {
            $gateway->save($record->with('geprueft_am', 'irgendwann'), $this->origin);
            self::fail('Ungültiges Datum geschrieben.');
        } catch (InvalidFieldValueException $e) {
            self::assertSame('geprueft_am', $e->field);
        }
        self::assertNull($this->storage->lastWrite);
    }

    public function testRulesSeeChangesCanAddValuesAndReportFieldErrors(): void
    {
        $rule = new class () implements RuleInterface {
            /** @var list<list<string>> */
            public array $seen = [];

            public function beforeSave(Change $change): void
            {
                $this->seen[] = $change->changedFields();
                if ($change->isChanged('status') && $change->value('status') === 'freigegeben') {
                    $change->set('geprueft_am', '2026-11-01');
                }
                if ($change->value('status') === 'verboten') {
                    $change->addError('status', 'Dieser Status ist nicht erlaubt.');
                }
            }
        };
        $gateway = $this->gateway(Models::antrag($rule));
        $record = $gateway->load('antrag', 'A-1');
        self::assertNotNull($record);

        $gateway->save($record->with('status', 'freigegeben'), $this->origin);
        self::assertSame(['status' => 'freigegeben', 'geprueft_am' => '2026-11-01'], $this->storage->lastWrite);
        self::assertSame([['status']], $rule->seen);

        try {
            $gateway->save($record->with('status', 'verboten'), $this->origin);
            self::fail('Regelfehler ignoriert.');
        } catch (DataValidationException $e) {
            self::assertSame(['status' => ['Dieser Status ist nicht erlaubt.']], $e->errors);
        }
    }

    public function testUnknownModelOrPersistence(): void
    {
        try {
            $this->gateway()->load('gibt-es-nicht', '1');
            self::fail('Unbekanntes Modell geladen.');
        } catch (DataModelException) {
            self::addToAssertionCount(1);
        }

        $elsewhere = DataModel::define('rest', 'REST')->persistence('api')->build();
        $this->expectException(DataModelException::class);
        $this->gateway($elsewhere)->load('rest', '1');
    }

    public function testTheCatalogIsDerivedFromTheModels(): void
    {
        $catalog = new DataCatalog(new DataModelRegistry([new StaticDataModelSource([Models::antrag()])]));

        $readable = $catalog->readable();
        self::assertSame('antrag', $readable[0]['name']);
        self::assertSame('Anträge', $readable[0]['label']);
        self::assertSame(['id', 'status', 'betrag', 'mail', 'geprueft_am'], array_column($readable[0]['fields'], 'name'));
        self::assertSame(['load' => true, 'find' => true, 'update' => true, 'create' => false], $readable[0]['operations']);
        self::assertSame(['name' => 'geprueft_am', 'label' => 'Geprüft am', 'type' => 'date', 'writable' => true], $readable[0]['fields'][4]);

        $writable = $catalog->writable();
        self::assertSame(['status', 'geprueft_am'], array_column($writable[0]['fields'], 'name'));
    }
}
