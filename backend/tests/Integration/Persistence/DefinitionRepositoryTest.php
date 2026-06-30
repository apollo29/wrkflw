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
}
