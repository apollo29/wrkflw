<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use WorkflowEngine\Persistence\PdoWorkflowRepository;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

#[CoversClass(PdoWorkflowRepository::class)]
#[Group('integration')]
final class DefinitionRepositoryTest extends IntegrationTestCase
{
    private PdoWorkflowRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PdoWorkflowRepository($this->pdo());
    }

    private const DEF = '{"startStep":"a","steps":{"a":{"type":"automatic"}}}';

    public function testSaveAutoIncrementsVersionAndActivatesLatest(): void
    {
        self::assertSame(1, $this->repo->saveDefinition('flow', 'Flow', self::DEF));
        self::assertSame(2, $this->repo->saveDefinition('flow', 'Flow v2', self::DEF));

        $defs = $this->repo->listDefinitions();
        $byVersion = [];
        foreach ($defs as $d) {
            $byVersion[$d['version']] = $d['active'];
        }

        self::assertFalse($byVersion[1]);
        self::assertTrue($byVersion[2]);
    }

    public function testFindDefinitionUsesLatestActiveVersion(): void
    {
        $this->repo->saveDefinition('flow', 'Flow', self::DEF);
        $this->repo->saveDefinition('flow', 'Flow v2', self::DEF);

        $def = $this->repo->findDefinition('flow');
        self::assertSame(2, $def->version);

        $json = $this->repo->findDefinitionJson('flow');
        self::assertNotNull($json);
        self::assertStringContainsString('"startStep":"a"', $json);
    }

    public function testFindDefinitionJsonReturnsNullWhenMissing(): void
    {
        self::assertNull($this->repo->findDefinitionJson('nope'));
    }

    public function testDraftDoesNotCreateNewVersionAndIsNotServed(): void
    {
        self::assertSame(1, $this->repo->saveDefinition('flow', 'Flow', self::DEF));

        // Als Entwurf speichern: gleiche Version, kein Inkrement.
        self::assertSame(1, $this->repo->saveDefinition('flow', 'Flow Entwurf', self::DEF, 'draft'));
        self::assertSame(1, $this->repo->saveDefinition('flow', 'Flow Entwurf 2', self::DEF, 'draft'));

        // Nur eine Version vorhanden, Status = draft.
        $defs = $this->repo->listDefinitions();
        self::assertCount(1, $defs);
        self::assertSame('draft', $defs[0]['status']);

        // Entwurf wird nicht ausgeliefert/getriggert.
        try {
            $this->repo->findDefinition('flow');
            self::fail('Entwurf sollte nicht ausgeliefert werden.');
        } catch (\WorkflowEngine\Exception\WorkflowException) {
            // erwartet
        }

        // Editor lädt die aktuelle Version dennoch (inkl. Entwurf).
        $json = $this->repo->findDefinitionJson('flow');
        self::assertNotNull($json);
    }

    public function testActivatingAfterDraftCreatesNewVersionAndServes(): void
    {
        $this->repo->saveDefinition('flow', 'Flow', self::DEF, 'draft');
        self::assertSame(2, $this->repo->saveDefinition('flow', 'Flow live', self::DEF, 'active'));

        $def = $this->repo->findDefinition('flow');
        self::assertSame(2, $def->version);

        $active = array_values(array_filter($this->repo->listDefinitions(), static fn (array $d): bool => $d['active']));
        self::assertCount(1, $active);
        self::assertSame(2, $active[0]['version']);
        self::assertSame('active', $active[0]['status']);
    }
}
