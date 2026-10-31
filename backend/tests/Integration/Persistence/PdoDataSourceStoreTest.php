<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Persistence\PdoDataSourceStore;
use WorkflowEngine\Rest\Actor;
use WorkflowEngine\Rest\AdminTrigger;
use WorkflowEngine\Rest\AuditEntry;
use WorkflowEngine\Rest\AuthType;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\IntegrationTestCase;
use WorkflowEngine\Tests\Support\RestFixtures;

final class PdoDataSourceStoreTest extends IntegrationTestCase
{
    private FrozenClock $clock;

    private PdoDataSourceStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-10-31 09:00:00');
        $this->store = new PdoDataSourceStore($this->pdo(), $this->clock);
    }

    public function testConnectionsKeepSecretsEncryptedInTheDatabase(): void
    {
        $connection = RestFixtures::connection(AuthType::ApiKey, 'klartext-geheim', 'X-Api-Key');
        $this->store->saveConnection($connection);

        self::assertEquals($connection, $this->store->findConnection('antrag-api'));
        self::assertCount(1, $this->store->allConnections());

        $raw = $this->pdo()->query('SELECT * FROM wf_rest_connection');
        self::assertNotFalse($raw);
        self::assertStringNotContainsString('klartext-geheim', (string) json_encode($raw->fetchAll(\PDO::FETCH_ASSOC)));

        $this->store->deleteConnection('antrag-api');
        self::assertNull($this->store->findConnection('antrag-api'));
    }

    public function testModelsRoundTrip(): void
    {
        $spec = RestFixtures::model(creatable: true);
        $this->store->saveModel($spec);

        self::assertEquals($spec, $this->store->findModel('antrag'));
        self::assertEquals([$spec], $this->store->allModels());

        $this->store->deleteModel('antrag');
        self::assertNull($this->store->findModel('antrag'));
    }

    public function testTriggersRoundTripAndRememberPolling(): void
    {
        $trigger = new AdminTrigger('offene', 'Offene Anträge', 'antrag', [new Condition('status', ConditionOperator::Eq, 'offen'), new Condition('betrag', ConditionOperator::Gt, 1000)], 'antrag-pruefung', ['antrag_id' => 'id'], 15, 7, true);
        $this->store->saveTrigger($trigger);
        self::assertEquals($trigger, $this->store->findTrigger('offene'));

        $this->store->markPolled('offene', $this->clock->now());
        self::assertEquals($this->clock->now(), $this->store->findTrigger('offene')?->lastPolledAt);
        self::assertCount(1, $this->store->allTriggers());
    }

    public function testAuditNewestFirstAndFilterable(): void
    {
        $actor = new Actor('u-1', 'Admin');
        $this->store->record(new AuditEntry($this->clock->now(), $actor, 'connection.created', 'connection', 'antrag-api', ['changed' => ['name']]));
        $this->clock->advance('+1 minute');
        $this->store->record(new AuditEntry($this->clock->now(), $actor, 'model.created', 'model', 'antrag'));

        $all = $this->store->entries();
        self::assertSame(['model.created', 'connection.created'], array_map(static fn (AuditEntry $e): string => $e->action, $all));
        self::assertEquals(new AuditEntry($all[1]->at, $actor, 'connection.created', 'connection', 'antrag-api', ['changed' => ['name']]), $all[1]);
        self::assertCount(1, $this->store->entries('model'));
    }
}
