<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\ArrayMailer;

#[CoversClass(SendEmailAction::class)]
final class SendEmailActionTest extends TestCase
{
    /** @param array<string,mixed> $context */
    private function instance(array $context): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'i1',
            definitionId: 'onboarding',
            definitionVersion: 1,
            currentStep: 'send_welcome',
            status: WorkflowInstance::RUNNING,
            context: $context,
        );
    }

    /** @param array<string,mixed> $config */
    private function step(array $config): Step
    {
        return Step::fromArray('send_welcome', [
            'type' => 'automatic',
            'action' => 'send_email',
            'config' => $config,
        ]);
    }

    public function testInterpolatesPlaceholdersAndSendsMail(): void
    {
        $mailer = new ArrayMailer();
        $action = new SendEmailAction($mailer);

        $action->execute(
            $this->instance(['name' => 'Mara', 'email' => 'mara@example.com']),
            $this->step([
                'to' => '{{email}}',
                'subject' => 'Willkommen, {{name}}!',
                'body' => 'Hallo {{name}}, schoen dich zu sehen.',
            ]),
        );

        $last = $mailer->last();
        self::assertNotNull($last);
        self::assertSame('mara@example.com', $last['to']);
        self::assertSame('Willkommen, Mara!', $last['subject']);
        self::assertSame('Hallo Mara, schoen dich zu sehen.', $last['body']);
    }

    public function testReturnsLastEmailToForContextMerge(): void
    {
        $mailer = new ArrayMailer();
        $action = new SendEmailAction($mailer);

        $result = $action->execute(
            $this->instance(['email' => 'x@y.de']),
            $this->step(['to' => '{{email}}', 'subject' => 'S', 'body' => 'B']),
        );

        self::assertSame('x@y.de', $result['lastEmailTo']);
    }

    public function testMissingPlaceholderBecomesEmptyString(): void
    {
        $mailer = new ArrayMailer();
        $action = new SendEmailAction($mailer);

        $action->execute(
            $this->instance(['name' => 'Mara']),
            $this->step(['to' => '{{email}}', 'subject' => 'Hi {{name}}', 'body' => 'x']),
        );

        $last = $mailer->last();
        self::assertNotNull($last);
        self::assertSame('', $last['to']);
        self::assertSame('Hi Mara', $last['subject']);
    }

    public function testPassesContextAsVars(): void
    {
        $mailer = new ArrayMailer();
        $action = new SendEmailAction($mailer);
        $context = ['name' => 'Mara', 'email' => 'm@x.de'];

        $action->execute(
            $this->instance($context),
            $this->step(['to' => '{{email}}', 'subject' => 'S', 'body' => 'B']),
        );

        $last = $mailer->last();
        self::assertNotNull($last);
        self::assertSame($context, $last['vars']);
    }
}
