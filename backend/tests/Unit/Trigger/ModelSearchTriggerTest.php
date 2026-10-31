<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Trigger;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Model\Condition;
use WorkflowEngine\Model\ConditionOperator;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Persistence\InMemoryPersistence;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Tests\Support\Models;
use WorkflowEngine\Trigger\ModelSearchTrigger;
use WorkflowEngine\Trigger\TriggerStart;

final class ModelSearchTriggerTest extends TestCase
{
    public function testStartsOneRunPerMatchingRecordWithMappedContext(): void
    {
        $storage = new InMemoryPersistence();
        $storage->seed('antrag', [Models::antragRow('A-1'), Models::antragRow('A-2', betrag: 300), Models::antragRow('A-3', archiviert: 1)]);
        $gateway = new DataGateway(
            new DataModelRegistry([new StaticDataModelSource([Models::antrag()])]),
            new PersistenceRegistry(['db' => $storage]),
        );

        $trigger = new ModelSearchTrigger(
            $gateway,
            model: 'antrag',
            conditions: [new Condition('status', ConditionOperator::Eq, 'offen'), new Condition('betrag', ConditionOperator::Gt, 1000)],
            definitionId: 'antrag-pruefung',
            inputs: ['antrag_id' => 'id', 'kontakt' => 'mail'],
        );

        self::assertEquals(
            [new TriggerStart('antrag-pruefung', ['antrag_id' => 'A-1', 'kontakt' => 'p@example.org'], 'antrag', 'A-1')],
            $trigger->poll(),
        );
    }
}
