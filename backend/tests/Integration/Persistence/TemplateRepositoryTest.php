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
        self::assertSame([['id' => 'welcome', 'name' => 'Willkommen', 'type' => 'email']], $list);

        $tpl = $this->repo->findTemplate('welcome');
        self::assertNotNull($tpl);
        self::assertSame('email', $tpl['type']);
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

    public function testListFiltersByType(): void
    {
        $this->repo->saveTemplate('mail', 'Mail', 'S', '<p>b</p>', 'email');
        $this->repo->saveTemplate('page', 'Seite', '', '<h1>Hallo</h1>', 'page');

        $pages = $this->repo->listTemplates('page');
        self::assertSame([['id' => 'page', 'name' => 'Seite', 'type' => 'page']], $pages);

        $emails = $this->repo->listTemplates('email');
        self::assertSame([['id' => 'mail', 'name' => 'Mail', 'type' => 'email']], $emails);

        self::assertCount(2, $this->repo->listTemplates());

        $page = $this->repo->findTemplate('page');
        self::assertNotNull($page);
        self::assertSame('page', $page['type']);
    }

    public function testDeleteRemovesTemplate(): void
    {
        $this->repo->saveTemplate('tmp', 'Tmp', 'S', 'B');
        self::assertNotNull($this->repo->findTemplate('tmp'));

        $this->repo->deleteTemplate('tmp');

        self::assertNull($this->repo->findTemplate('tmp'));
        self::assertSame([], $this->repo->listTemplates());
    }
}
