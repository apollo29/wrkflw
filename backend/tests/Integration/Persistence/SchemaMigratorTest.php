<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use WorkflowEngine\Action\ActionRegistry;
use WorkflowEngine\Engine\SymfonyExpressionEvaluator;
use WorkflowEngine\Engine\WorkflowEngine;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Persistence\SchemaMigrator;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

/** Bestehende 2.x-Daten laufen nach der Migration unverändert weiter (F-114). */
final class SchemaMigratorTest extends IntegrationTestCase
{
    private const string WAITING = '11111111-1111-4111-8111-111111111111';

    private function dropAll(): void
    {
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['wf_history', 'wf_instance', 'wf_definition', 'wf_template', 'wf_trigger_lock', 'wf_schema_version'] as $table) {
            $this->pdo()->exec("DROP TABLE IF EXISTS {$table}");
        }
        $this->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function migrator(): SchemaMigrator
    {
        $backend = dirname(__DIR__, 3);

        return new SchemaMigrator($this->pdo(), $backend . '/schema.sql', $backend . '/migrations');
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $result = $this->pdo()->query('SHOW TABLES');
        self::assertNotFalse($result);

        return array_values(array_filter($result->fetchAll(\PDO::FETCH_COLUMN), is_string(...)));
    }

    public function testFreshDatabaseGetsTheSchemaAndAllMigrationsMarked(): void
    {
        $this->dropAll();

        $applied = $this->migrator()->migrate();

        self::assertSame(['schema.sql'], $applied);
        self::assertContains('wf_trigger_lock', $this->tables());
        self::assertSame([], $this->migrator()->migrate(), 'zweiter Lauf ohne Wirkung');
        self::assertSame(
            ['001_wf_template_type.sql', '002_wf_definition_status.sql', '3.0.0-001-v3.sql'],
            $this->migrator()->appliedMigrations(),
        );
    }

    public function testA2xDatabaseIsMigratedAndItsRunsContinue(): void
    {
        $this->dropAll();
        $this->load2x();

        $applied = $this->migrator()->migrate();

        self::assertSame(['001_wf_template_type.sql', '002_wf_definition_status.sql', '3.0.0-001-v3.sql'], $applied);
        self::assertSame([], $this->migrator()->migrate());

        $clock = new FrozenClock('2026-10-31 09:00:00');
        $repo = new PdoWorkflowRepository($this->pdo(), $clock);
        $engine = new WorkflowEngine($repo, new ActionRegistry(), new SymfonyExpressionEvaluator($clock), $clock);

        $continued = $engine->handleEvent(self::WAITING, 'submit', ['ok' => true]);
        self::assertSame(InstanceStatus::Completed, $continued->status);
        self::assertSame('fertig', $continued->currentStep);
        self::assertSame('A-7', $continued->context['antrag_id']);

        $started = $engine->start('onboarding', ['person' => 'P-1']);
        self::assertSame(InstanceStatus::WaitingEvent, $started->status);
        self::assertSame(1, $started->definitionVersion);

        self::assertNotEmpty($repo->listDefinitions());
        self::assertSame('Willkommen', $repo->listDefinitions()[0]->name);
    }

    private function load2x(): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/schema-2x.sql');
        foreach (self::statements($schema) as $statement) {
            $this->pdo()->exec($statement);
        }

        // 2.x-Bestand: wf_template ohne type, wf_definition ohne status, wie vor den Migrationen 001/002.
        $this->pdo()->exec('ALTER TABLE wf_template DROP COLUMN type');
        $this->pdo()->exec('ALTER TABLE wf_definition DROP COLUMN status');

        $definition = json_encode([
            'id' => 'onboarding',
            'name' => 'Willkommen',
            'startStep' => 'angaben',
            'steps' => [
                'angaben' => ['type' => 'interactive', 'ui' => ['fields' => [['name' => 'ok']]], 'transitions' => [['to' => 'fertig', 'event' => 'submit']]],
                'fertig' => ['type' => 'automatic', 'transitions' => []],
            ],
        ], JSON_THROW_ON_ERROR);
        $insert = $this->pdo()->prepare("INSERT INTO wf_definition (id, version, name, definition, active) VALUES ('onboarding', 1, 'Willkommen', ?, 1)");
        $insert->execute([$definition]);

        $this->pdo()->prepare(
            "INSERT INTO wf_instance (id, definition_id, definition_ver, current_step, status, context, attempts)
             VALUES (?, 'onboarding', 1, 'angaben', 'waiting_event', ?, 0)",
        )->execute([self::WAITING, json_encode(['antrag_id' => 'A-7', '__appliedEventIds' => ['x']], JSON_THROW_ON_ERROR)]);
        $this->pdo()->prepare("INSERT INTO wf_history (instance_id, kind, step, detail) VALUES (?, 'start', 'angaben', '{}')")->execute([self::WAITING]);
        $this->pdo()->exec("INSERT INTO wf_template (id, name, subject, body) VALUES ('alt', 'Alte Vorlage', 'Betreff', 'Inhalt')");
    }

    /**
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $lines = array_filter(preg_split('/\r?\n/', $sql) ?: [], static fn (string $l): bool => !str_starts_with(ltrim($l), '--'));

        return array_values(array_filter(array_map('trim', explode(';', implode("\n", $lines))), static fn (string $s): bool => $s !== ''));
    }
}
