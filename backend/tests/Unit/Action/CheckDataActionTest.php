<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\CheckDataAction;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\StepType;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModelException;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Persistence\InMemoryPersistence;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\Models;

final class CheckDataActionTest extends TestCase
{
    private CheckDataAction $action;

    protected function setUp(): void
    {
        $storage = new InMemoryPersistence();
        $storage->seed('antrag', [Models::antragRow('A-1'), Models::antragRow('A-3', archiviert: 1)]);
        $gateway = new DataGateway(
            new DataModelRegistry([new StaticDataModelSource([Models::antrag()])]),
            new PersistenceRegistry(['db' => $storage]),
        );
        $this->action = new CheckDataAction($gateway, new PlaceholderRenderer(new FrozenClock()));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function check(array $config, string $antragId = 'A-1'): array
    {
        $instance = new WorkflowInstance('i', 'w', 1, 's', InstanceStatus::Running, ['antrag_id' => $antragId]);

        return $this->action->execute($instance, new Step('s', StepType::Automatic, 'check_data', $config))->context;
    }

    public function testReadsTheFieldAndTheExtraFieldsIntoTheContext(): void
    {
        $result = $this->check(['entity' => 'antrag', 'id' => '{{antrag_id}}', 'field' => 'status', 'fields' => ['betrag', 'mail', 'betrag'], 'as' => 'antrag']);

        self::assertSame([
            'antrag' => 'offen',
            'antragFound' => true,
            'antrag_betrag' => 1250,
            'antrag_mail' => 'p@example.org',
        ], $result);
    }

    public function testNotFoundIsAnAnswerAndStillSetsEveryKey(): void
    {
        $config = ['entity' => 'antrag', 'id' => '{{antrag_id}}', 'field' => 'status', 'fields' => ['betrag'], 'as' => 'antrag'];
        $expected = ['antrag' => null, 'antragFound' => false, 'antrag_betrag' => null];

        self::assertSame($expected, $this->check($config, 'A-9'));
        self::assertSame($expected, $this->check($config, 'A-3'), 'ausserhalb der Teilmenge');
        self::assertSame($expected, $this->check($config, ''), 'ohne ID');
    }

    public function testDefaultResultVariable(): void
    {
        self::assertSame(['checkedValue' => 'offen', 'checkedValueFound' => true], $this->check(['entity' => 'antrag', 'id' => 'A-1', 'field' => 'status']));
    }

    public function testAllColumnsDoesNotExistAndUnknownFieldsAreErrors(): void
    {
        foreach ([['fields' => ['*']], ['field' => 'iban'], ['fields' => ['iban']]] as $extra) {
            try {
                $this->check($extra + ['entity' => 'antrag', 'id' => 'A-1', 'field' => 'status']);
                self::fail('Nicht abgelehnt: ' . json_encode($extra));
            } catch (DataModelException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDescribesItselfForTheEditor(): void
    {
        self::assertSame('Datencheck', $this->action->label());
        self::assertSame(
            ['entity', 'id', 'field', 'fields', 'as'],
            array_column($this->action->configSchema(), 'name'),
        );
    }
}
