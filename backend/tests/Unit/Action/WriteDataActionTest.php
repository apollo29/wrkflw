<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\WriteDataAction;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\StepType;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\InvalidFieldValueException;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Persistence\InMemoryPersistence;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\Models;

/** Daten schreiben (F-34 bis F-38). */
final class WriteDataActionTest extends TestCase
{
    private InMemoryPersistence $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryPersistence();
        $this->storage->seed('antrag', [Models::antragRow('A-1')]);
        $this->storage->seed('diplom', [['id' => 'D-1', 'titel' => 'J+S', 'stufe' => 1, 'kodex' => 'offen']]);
    }

    private function action(): WriteDataAction
    {
        $diplom = DataModel::define('diplom', 'Diplome')
            ->text('titel', 'Titel')->writable()
            ->number('stufe', 'Stufe')->writable()
            ->text('kodex', 'Kodex')->writable()
            ->updatable()
            ->creatable()
            ->build();
        $gateway = new DataGateway(
            new DataModelRegistry([new StaticDataModelSource([Models::antrag(), $diplom])]),
            new PersistenceRegistry(['db' => $this->storage]),
        );
        $clock = new FrozenClock('2026-10-31 14:00:00');

        return new WriteDataAction($gateway, new PlaceholderRenderer($clock), new SymfonyExpressionEvaluator($clock));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function write(array $config, array $context = []): array
    {
        $instance = new WorkflowInstance('inst-1', 'antrag-pruefung', 3, 'speichern', InstanceStatus::Running, $context + ['antrag_id' => 'A-1']);

        return $this->action()->execute($instance, new Step('speichern', StepType::Automatic, 'write_data', $config))->context;
    }

    public function testWritesValuesWithPlaceholdersAndTheClock(): void
    {
        $result = $this->write(['entity' => 'antrag', 'id' => '{{antrag_id}}', 'values' => ['status' => 'freigegeben', 'geprueft_am' => '{{now}}']]);

        self::assertSame(['written' => true, 'writtenCount' => 2], $result);
        self::assertSame(['status' => 'freigegeben', 'geprueft_am' => '2026-10-31'], $this->storage->lastWrite);
        self::assertSame('workflow:antrag-pruefung@3#inst-1/speichern', $this->storage->lastOrigin?->toString());
    }

    public function testNothingChangedMeansNotWritten(): void
    {
        $result = $this->write(['entity' => 'antrag', 'id' => 'A-1', 'values' => ['status' => 'offen'], 'as' => 'gespeichert']);

        self::assertSame(['gespeichert' => false, 'gespeichertCount' => 0], $result);
        self::assertNull($this->storage->lastWrite);
    }

    public function testConditionalValuesDistinguishClearingFromLeavingAlone(): void
    {
        $values = [
            'kodex' => ['wert' => 'unterzeichnet', 'wenn' => "context['gelesen'] == true", 'sonst' => ''],
            'titel' => ['wert' => 'neu', 'wenn' => "context['gelesen'] == true"],
        ];

        $this->write(['entity' => 'diplom', 'id' => 'D-1', 'values' => $values], ['gelesen' => true]);
        self::assertSame(['titel' => 'neu', 'kodex' => 'unterzeichnet'], $this->storage->lastWrite);

        $this->write(['entity' => 'diplom', 'id' => 'D-1', 'values' => $values], ['gelesen' => false]);
        self::assertSame(['kodex' => ''], $this->storage->lastWrite, '«sonst» leer setzt leer, fehlendes «sonst» lässt stehen');
        self::assertSame('neu', $this->storage->row('diplom', 'D-1')['titel'] ?? null);
    }

    public function testASinglePlaceholderKeepsItsType(): void
    {
        $this->write(['entity' => 'diplom', 'id' => 'D-1', 'values' => ['stufe' => '{{stufe_neu}}']], ['stufe_neu' => 2]);

        self::assertSame(['stufe' => 2], $this->storage->lastWrite);
    }

    public function testMissingRecordIsCreatedOnlyWhenAllowedAndRequested(): void
    {
        self::assertSame(
            ['written' => false, 'writtenCount' => 0],
            $this->write(['entity' => 'diplom', 'id' => 'D-2', 'values' => ['titel' => 'Leiter'], 'anlegen' => 'false']),
        );

        $created = $this->write(['entity' => 'diplom', 'id' => 'D-2', 'values' => ['titel' => 'Leiter'], 'anlegen' => true]);
        self::assertSame(['written' => true, 'writtenCount' => 1], $created);
        self::assertSame(['id' => 'D-2', 'titel' => 'Leiter'], $this->storage->row('diplom', 'D-2'));

        $this->expectException(DataModelException::class);
        $this->write(['entity' => 'antrag', 'id' => 'A-9', 'values' => ['status' => 'neu'], 'anlegen' => true]);
    }

    public function testOnlySimpleColumnNamesAndNoIncompleteConfiguration(): void
    {
        self::assertSame(['written' => false, 'writtenCount' => 0], $this->write(['entity' => 'antrag', 'id' => 'A-1', 'values' => ['a;drop' => 'x']]));
        self::assertSame(['written' => false, 'writtenCount' => 0], $this->write(['entity' => 'antrag', 'id' => '', 'values' => ['status' => 'x']]));
        self::assertNull($this->storage->lastWrite);
    }

    public function testNonWritableFieldsAndInvalidValuesAreRejected(): void
    {
        try {
            $this->write(['entity' => 'antrag', 'id' => 'A-1', 'values' => ['betrag' => 1]]);
            self::fail('Nicht schreibbares Feld geschrieben.');
        } catch (DataModelException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(InvalidFieldValueException::class);
        $this->write(['entity' => 'antrag', 'id' => 'A-1', 'values' => ['geprueft_am' => 'bald']]);
    }

    public function testDescribesItselfForTheEditor(): void
    {
        self::assertSame('Daten schreiben', $this->action()->label());
        self::assertSame(
            ['entity' => 'write-entity-ref', 'id' => 'text', 'values' => 'field-value-map', 'anlegen' => 'boolean', 'as' => 'text'],
            array_column($this->action()->configSchema(), 'type', 'name'),
        );
    }
}
