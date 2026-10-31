<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Integration\Persistence;

use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Persistence\PdoTemplateRepository;
use WorkflowEngine\Persistence\PdoTriggerLock;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\IntegrationTestCase;

final class PdoTemplateAndLockTest extends IntegrationTestCase
{
    public function testTemplatesRoundTripByType(): void
    {
        $repo = new PdoTemplateRepository($this->pdo());
        $mail = new Template('antrag-ok', 'Antrag bestätigt', TemplateType::Email, 'Ihr Antrag {{antrag_id}}', '<p>Hallo</p>');
        $page = new Template('antrag-info', 'Hinweise zum Antrag', TemplateType::Page, '', '<p>Info</p>');
        $repo->saveTemplate($mail);
        $repo->saveTemplate($page);

        self::assertEquals($mail, $repo->findTemplate('antrag-ok'));
        self::assertEquals([$page], $repo->listTemplates(TemplateType::Page));
        self::assertCount(2, $repo->listTemplates());

        $repo->saveTemplate(new Template('antrag-ok', 'Neu', TemplateType::Email, 'B', 'I'));
        self::assertSame('Neu', $repo->findTemplate('antrag-ok')?->name);

        $repo->deleteTemplate('antrag-ok');
        self::assertNull($repo->findTemplate('antrag-ok'));
    }

    public function testTriggerLockAllowsOneStartPerSubjectUntilItExpires(): void
    {
        $clock = new FrozenClock('2026-10-31 09:00:00');
        $lock = new PdoTriggerLock($this->pdo());
        $until = $clock->now()->modify('+7 days');

        self::assertTrue($lock->acquire('frist', 'antrag:A-1', $clock->now(), $until));
        self::assertFalse($lock->acquire('frist', 'antrag:A-1', $clock->now(), $until));
        self::assertTrue($lock->acquire('frist', 'antrag:A-2', $clock->now(), $until));
        self::assertTrue($lock->acquire('anderer', 'antrag:A-1', $clock->now(), $until));

        $clock->advance('+7 days +1 second');
        self::assertTrue($lock->acquire('frist', 'antrag:A-1', $clock->now(), $clock->now()->modify('+7 days')));

        $lock->release('frist', 'antrag:A-2');
        self::assertTrue($lock->acquire('frist', 'antrag:A-2', $clock->now(), $until));
    }
}
