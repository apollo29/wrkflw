<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\FieldType;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Rest\RestDataModelSource;
use WorkflowEngine\Rest\RestModelSpec;
use WorkflowEngine\Rest\RestOptions;
use WorkflowEngine\Rest\RestSpecException;
use WorkflowEngine\Tests\Support\InMemoryDataSourceStore;
use WorkflowEngine\Tests\Support\Models;
use WorkflowEngine\Tests\Support\RestFixtures;

final class RestModelSpecTest extends TestCase
{
    public function testBecomesADataModelThatLooksLikeTheDatabaseVariant(): void
    {
        $model = RestFixtures::model()->toDataModel(allowWrites: true);

        self::assertSame('antrag', $model->name);
        self::assertSame('rest', $model->persistence);
        self::assertSame(['id', 'status', 'betrag', 'mail', 'geprueft_am'], array_keys($model->fields));
        self::assertSame(['status', 'geprueft_am'], array_keys($model->writableFields()));
        self::assertSame('kontakt.mail', $model->field('mail')->storageName());
        self::assertSame(FieldType::Number, $model->field('betrag')->type);
        self::assertTrue($model->canUpdate);
        self::assertFalse($model->canCreate, 'ohne Pfad für Anlegen');
        self::assertSame(['archiviert' => 'false'], $model->subset);
    }

    public function testTheHostAppCanBlockWritesEntirely(): void
    {
        $model = RestFixtures::model(creatable: true)->toDataModel(allowWrites: false);

        self::assertFalse($model->canUpdate);
        self::assertFalse($model->canCreate);
        self::assertSame([], $model->writableFields());
    }

    public function testRoundTripThroughTheStoredForm(): void
    {
        $spec = RestFixtures::model(creatable: true);

        self::assertEquals($spec, RestModelSpec::fromArray($spec->toArray()));
    }

    public function testInvalidSpecsAreRejected(): void
    {
        foreach ([
            ['name' => 'x'],
            ['name' => 'x', 'label' => 'X', 'connection' => 'c', 'fields' => [['name' => 'id', 'type' => 'farbe', 'path' => 'id']]],
            ['name' => 'x', 'label' => 'X', 'connection' => 'c', 'fields' => [['name' => 'id', 'path' => 'id']], 'operations' => ['load' => ['method' => 'DELETE', 'path' => '/x/{id}']]],
            ['name' => 'x', 'label' => 'X', 'connection' => 'c', 'fields' => [['name' => 'id', 'path' => 'id']], 'operations' => ['update' => ['method' => 'PATCH', 'path' => '/x']]],
        ] as $i => $data) {
            try {
                RestModelSpec::fromArray($data);
                self::fail("Fall {$i} nicht abgelehnt.");
            } catch (RestSpecException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRestModelsCannotReplaceHostModels(): void
    {
        $store = new InMemoryDataSourceStore();
        $store->saveModel(RestFixtures::model());
        $registry = new DataModelRegistry([
            new StaticDataModelSource([Models::antrag()]),
            new RestDataModelSource($store, new RestOptions()),
        ]);

        $this->expectException(DataModelException::class);
        $this->expectExceptionMessage('mehrfach');
        $registry->all();
    }
}
