<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use WorkflowEngine\Persistence\PdoTemplateRepository;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

#[CoversClass(PdoTemplateRepository::class)]
#[Group('integration')]
final class TemplateRepositoryTest extends IntegrationTestCase
{
    private PdoTemplateRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new PdoTemplateRepository($this->pdo());
    }

    public function testSaveListFindAndUpdate(): void
    {
        self::assertSame([], $this->repo->listTemplates());
        self::assertNull($this->repo->findTemplate('welcome'));

        $this->repo->saveTemplate('welcome', 'Willkommen', 'Hallo {{name}}', '<p>Hi {{name}}</p>');

        $list = $this->repo->listTemplates();
        self::assertSame([['id' => 'welcome', 'name' => 'Willkommen']], $list);

        $tpl = $this->repo->findTemplate('welcome');
        self::assertNotNull($tpl);
        self::assertSame('Hallo {{name}}', $tpl['subject']);
        self::assertSame('<p>Hi {{name}}</p>', $tpl['body']);

        // Update (gleiche id) ueberschreibt.
        $this->repo->saveTemplate('welcome', 'Willkommen v2', 'Neu', '<p>Neu</p>');
        $updated = $this->repo->findTemplate('welcome');
        self::assertNotNull($updated);
        self::assertSame('Willkommen v2', $updated['name']);
        self::assertSame('Neu', $updated['subject']);
        self::assertCount(1, $this->repo->listTemplates());
    }
}
