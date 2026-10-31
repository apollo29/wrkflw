<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration;

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Action\CheckDataAction;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Action\SubWorkflowAction;
use WorkflowEngine\Action\WriteDataAction;
use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Definition\DefinitionParser;
use WorkflowEngine\Definition\DefinitionStatus;
use WorkflowEngine\Definition\DefinitionValidator;
use WorkflowEngine\Definition\KnownActionRule;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Model\DataGateway;
use WorkflowEngine\Model\DataModel;
use WorkflowEngine\Model\DataModelRegistry;
use WorkflowEngine\Model\DataModelStepRule;
use WorkflowEngine\Model\StaticDataModelSource;
use WorkflowEngine\Persistence\PdoTablePersistence;
use WorkflowEngine\Persistence\PdoTemplateRepository;
use WorkflowEngine\Persistence\PdoTriggerLock;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Persistence\PersistenceRegistry;
use WorkflowEngine\Tests\Support\ArrayMailer;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

/**
 * Der Beispielablauf aus 9.13 gegen echtes MariaDB: Ablage, Datenmodell auf einer
 * Host-Tabelle, Vorlagen und Hintergrundlauf.
 */
final class EndToEndTest extends IntegrationTestCase
{
    private FrozenClock $clock;

    private PdoWorkflowRepository $repo;

    private ArrayMailer $mailer;

    private WorkflowEngine $engine;

    private WorkflowRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE antrag (id VARCHAR(20) PRIMARY KEY, status VARCHAR(40), betrag INT, mail VARCHAR(200), geprueft_am DATE NULL, archiviert TINYINT(1) NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO antrag VALUES ('A-1024', 'offen', 1250, 'p@example.org', NULL, 0), ('A-1', 'offen', 500, 'k@example.org', NULL, 0)");

        $this->clock = new FrozenClock('2026-10-31 09:00:00');
        $this->repo = new PdoWorkflowRepository($pdo, $this->clock);
        $templates = new PdoTemplateRepository($pdo);
        $templates->saveTemplate(new Template('antrag-ok', 'Antrag bestätigt', TemplateType::Email, 'Ihr Antrag {{antrag_id}} ist freigegeben', 'Betrag {{antrag_betrag}}'));

        $model = DataModel::define('antrag', 'Anträge')
            ->text('status', 'Status')->writable()
            ->number('betrag', 'Betrag')
            ->text('mail', 'E-Mail')
            ->date('geprueft_am', 'Geprüft am')->writable()
            ->subset(['archiviert' => 0])
            ->updatable()
            ->build();
        $models = new DataModelRegistry([new StaticDataModelSource([$model])]);
        $data = new DataGateway($models, new PersistenceRegistry(['db' => new PdoTablePersistence($pdo)]));

        $expressions = new SymfonyExpressionEvaluator($this->clock);
        $renderer = new PlaceholderRenderer($this->clock);
        $actions = new ActionRegistry();
        $this->mailer = new ArrayMailer();
        $this->engine = new WorkflowEngine($this->repo, $actions, $expressions, $this->clock);
        $actions->register('send_email', new SendEmailAction($this->mailer, $templates, $renderer));
        $actions->register('start_workflow', new SubWorkflowAction(fn (): WorkflowEngine => $this->engine));
        $actions->register('check_data', new CheckDataAction($data, $renderer));
        $actions->register('write_data', new WriteDataAction($data, $renderer, $expressions));
        $this->runner = new WorkflowRunner($this->engine, $this->repo, new PdoTriggerLock($pdo), $this->clock);

        $validator = new DefinitionValidator($expressions, [new KnownActionRule($actions), new DataModelStepRule($models)]);
        foreach ([
            DefinitionParser::fromJson((string) file_get_contents(dirname(__DIR__) . '/Fixtures/antrag-pruefung.json')),
            DefinitionParser::fromArray(['id' => 'archivierung', 'startStep' => 'archivieren', 'steps' => ['archivieren' => []]]),
        ] as $definition) {
            $validator->validate($definition);
            $this->repo->save($definition, DefinitionStatus::Active);
        }
    }

    public function testReviewPathEndToEnd(): void
    {
        $instance = $this->engine->start('antrag-pruefung', ['antrag_id' => 'A-1024']);
        self::assertSame('pruefen', $this->repo->findInstance($instance->id)?->currentStep);

        $this->engine->handleEvent($instance->id, 'submit', ['bemerkung' => 'passt'], 'klick-1');
        $this->engine->handleEvent($instance->id, 'submit', ['bemerkung' => 'doppelt'], 'klick-1');

        $done = $this->repo->findInstance($instance->id);
        self::assertSame(InstanceStatus::Completed, $done->status);
        self::assertSame('passt', $done->context['bemerkung']);
        self::assertSame(['freigegeben', '2026-10-31'], $this->antrag('A-1024'));
        self::assertSame('Ihr Antrag A-1024 ist freigegeben', $this->mailer->last()->subject);
        self::assertSame(2, $this->repo->listDefinitions()[0]->totalInstances + $this->repo->listDefinitions()[1]->totalInstances);
    }

    public function testTimerPathContinuesThroughTheRunner(): void
    {
        $instance = $this->engine->start('antrag-pruefung', ['antrag_id' => 'A-1']);
        self::assertSame(InstanceStatus::WaitingTimer, $this->repo->findInstance($instance->id)?->status);

        $this->clock->advance('+23 hours');
        self::assertSame(0, $this->runner->tick()->woken);

        $this->clock->advance('+1 hour');
        $stats = $this->runner->tick();

        self::assertSame(1, $stats->woken);
        self::assertSame(InstanceStatus::Completed, $this->repo->findInstance($instance->id)?->status);
        self::assertSame(['freigegeben', '2026-11-01'], $this->antrag('A-1'));
    }

    /**
     * @return array{mixed, mixed}
     */
    private function antrag(string $id): array
    {
        $statement = $this->pdo()->prepare('SELECT status, geprueft_am FROM antrag WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($row);

        return [$row[0], $row[1]];
    }
}
