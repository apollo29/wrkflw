<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use WorkflowEngine\Model\ChangeOrigin;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\Field;
use WorkflowEngine\Model\FieldType;
use WorkflowEngine\Model\Record;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Persistence\PdoTablePersistence;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

/** Datenmodelle über eine echte Tabelle der Host-App (F-129). */
final class PdoTablePersistenceTest extends IntegrationTestCase
{
    private DataModel $model;

    private PdoTablePersistence $persistence;

    private DataGateway $gateway;

    private ChangeOrigin $origin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo()->exec(<<<'SQL'
            CREATE TABLE test_antrag (
                antrag_id INT AUTO_INCREMENT PRIMARY KEY,
                status VARCHAR(40) NOT NULL DEFAULT 'offen',
                betrag DECIMAL(10,2) NULL,
                kontakt_mail VARCHAR(200) NULL,
                geprueft_am DATE NULL,
                geaendert_um DATETIME NULL,
                dringend TINYINT(1) NOT NULL DEFAULT 0,
                archiviert TINYINT(1) NOT NULL DEFAULT 0,
                iban VARCHAR(40) NULL
            ) ENGINE=InnoDB
            SQL);
        $this->pdo()->exec(<<<'SQL'
            INSERT INTO test_antrag (antrag_id, status, betrag, kontakt_mail, geprueft_am, dringend, archiviert, iban) VALUES
                (1, 'offen', 1250.00, 'p@example.org', NULL, 1, 0, 'CH00 0000'),
                (2, 'offen', 500.50, '', '2026-10-01', 0, 0, NULL),
                (3, 'offen', 9000.00, 'x@example.org', NULL, 0, 1, NULL)
            SQL);

        $this->model = new DataModel(
            name: 'antrag',
            label: 'Anträge',
            idField: 'id',
            fields: [
                new Field('id', 'ID', FieldType::Number, 'antrag_id'),
                Field::text('status', 'Status')->asWritable(),
                Field::number('betrag', 'Betrag'),
                Field::text('mail', 'E-Mail', 'kontakt_mail'),
                Field::date('geprueft_am', 'Geprüft am')->asWritable(),
                Field::datetime('geaendert_um', 'Geändert um')->asWritable(),
                Field::bool('dringend', 'Dringend')->asWritable(),
            ],
            source: 'test_antrag',
            subset: ['archiviert' => 0],
            canUpdate: true,
            canCreate: true,
        );
        $this->persistence = new PdoTablePersistence($this->pdo(), new \DateTimeZone('Europe/Zurich'));
        $this->gateway = new DataGateway(
            new DataModelRegistry([new StaticDataModelSource([$this->model])]),
            new PersistenceRegistry(['db' => $this->persistence]),
        );
        $this->origin = new ChangeOrigin('antrag-pruefung', 3, 'inst-1', 'speichern');
    }

    public function testLoadMapsColumnsAndTypesAndHidesUndescribedColumns(): void
    {
        $record = $this->gateway->load('antrag', '1');

        self::assertNotNull($record);
        self::assertSame([
            'id' => '1',
            'status' => 'offen',
            'betrag' => 1250,
            'mail' => 'p@example.org',
            'geprueft_am' => null,
            'geaendert_um' => null,
            'dringend' => true,
        ], $record->values);
        self::assertArrayNotHasKey('iban', $record->values);
        self::assertNull($this->gateway->load('antrag', '3'), 'ausserhalb der Teilmenge');
        self::assertNull($this->gateway->load('antrag', '99'));
    }

    public function testFindWithConditions(): void
    {
        $ids = fn (Condition ...$conditions): array => array_map(
            static fn (Record $r): string => $r->id,
            $this->gateway->find('antrag', array_values($conditions)),
        );

        self::assertSame(['1', '2'], $ids(new Condition('status', ConditionOperator::Eq, 'offen')));
        self::assertSame(['1'], $ids(new Condition('betrag', ConditionOperator::Gt, 1000)));
        self::assertSame(['2'], $ids(new Condition('mail', ConditionOperator::Empty)));
        self::assertSame(['2'], $ids(new Condition('geprueft_am', ConditionOperator::Le, '2026-10-31')));
        self::assertSame(['1'], $ids(new Condition('dringend', ConditionOperator::Eq, true)));
        self::assertCount(1, $this->gateway->find('antrag', [], limit: 1));
    }

    public function testUpdateWritesOnlyChangedFieldsAndConvertsTypes(): void
    {
        $record = $this->gateway->load('antrag', '1');
        self::assertNotNull($record);

        $result = $this->gateway->save(
            $record->with('status', 'freigegeben')
                ->with('geprueft_am', '2026-10-31')
                ->with('geaendert_um', '2026-10-31T14:32:05+01:00')
                ->with('dringend', false),
            $this->origin,
        );

        self::assertTrue($result->written);
        $row = $this->row(1);
        self::assertSame('freigegeben', $row['status']);
        self::assertSame('2026-10-31', $row['geprueft_am']);
        self::assertSame('2026-10-31 14:32:05', $row['geaendert_um']);
        self::assertSame('0', (string) self::scalar($row['dringend']));
        self::assertSame('CH00 0000', $row['iban'], 'nicht beschriebene Spalten bleiben unberührt');

        $again = $this->gateway->load('antrag', '1');
        self::assertNotNull($again);
        self::assertSame('2026-10-31T14:32:05+01:00', $again->get('geaendert_um'));
        self::assertFalse($this->gateway->save($again->with('status', 'freigegeben'), $this->origin)->written);
    }

    public function testRecordsOutsideTheSubsetCannotBeUpdated(): void
    {
        self::assertFalse($this->persistence->update($this->model, '3', ['status' => 'x'], $this->origin));
        self::assertSame('offen', $this->row(3)['status']);
    }

    public function testCreateSetsTheSubsetAndReturnsTheGeneratedId(): void
    {
        $result = $this->gateway->save(Record::create('antrag')->with('status', 'neu')->with('dringend', true), $this->origin);

        self::assertTrue($result->created);
        self::assertMatchesRegularExpression('/^\d+$/', $result->id);
        $row = $this->row((int) $result->id);
        self::assertSame('neu', $row['status']);
        self::assertSame('0', (string) self::scalar($row['archiviert']));
        self::assertSame('1', (string) self::scalar($row['dringend']));
    }

    private static function scalar(mixed $value): string|int|float|bool|null
    {
        self::assertTrue($value === null || is_scalar($value));

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM test_antrag WHERE antrag_id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
