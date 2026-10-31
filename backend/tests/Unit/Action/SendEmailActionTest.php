<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Unit\Action;

use PHPUnit\Framework\TestCase;
use WorkflowEngine\Action\SendEmailAction;
use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateType;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Definition\StepType;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\InstanceStatus;
use WorkflowEngine\Instance\WorkflowInstance;
use WorkflowEngine\Tests\Support\ArrayMailer;
use WorkflowEngine\Tests\Support\FrozenClock;
use WorkflowEngine\Tests\Support\InMemoryTemplateRepository;

final class SendEmailActionTest extends TestCase
{
    private ArrayMailer $mailer;

    private InMemoryTemplateRepository $templates;

    private SendEmailAction $action;

    protected function setUp(): void
    {
        $this->mailer = new ArrayMailer();
        $this->templates = new InMemoryTemplateRepository();
        $this->action = new SendEmailAction($this->mailer, $this->templates, new PlaceholderRenderer(new FrozenClock()));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function send(array $config): void
    {
        $instance = new WorkflowInstance('i', 'w', 1, 's', InstanceStatus::Running, [
            'name' => 'Muster',
            'mail' => 'p@example.org',
            'chef' => 'c@example.org',
            '__parent' => ['instanceId' => 'x'],
        ]);
        $result = $this->action->execute($instance, new Step('s', StepType::Automatic, 'send_email', $config));
        self::assertSame(['lastEmailTo' => $this->mailer->last()->to], $result->context);
    }

    public function testInlineContentWithPlaceholders(): void
    {
        $this->send([
            'to' => '{{mail}}',
            'cc' => '{{chef}}, b@example.org; ',
            'subject' => 'Hallo {{name}}',
            'body' => '<p>Am {{now}}</p>',
        ]);

        $mail = $this->mailer->sent[0];
        self::assertSame('p@example.org', $mail->to);
        self::assertSame('', $mail->from);
        self::assertSame(['c@example.org', 'b@example.org'], $mail->cc);
        self::assertSame([], $mail->bcc);
        self::assertSame('Hallo Muster', $mail->subject);
        self::assertSame('<p>Am 2026-10-31</p>', $mail->body);
        self::assertArrayNotHasKey('__parent', $mail->vars);
    }

    public function testATemplateOverridesSubjectAndBody(): void
    {
        $this->templates->saveTemplate(new Template('t', 'Vorlage', TemplateType::Email, 'Für {{name}}', 'Inhalt'));

        $this->send(['to' => 'x@example.org', 'templateId' => 't', 'subject' => 'ignoriert', 'body' => 'ignoriert']);

        self::assertSame('Für Muster', $this->mailer->sent[0]->subject);
        self::assertSame('Inhalt', $this->mailer->sent[0]->body);
    }

    public function testMissingRecipientOrTemplateIsATechnicalError(): void
    {
        foreach ([['to' => '{{fehlt}}'], ['to' => 'x@example.org', 'templateId' => 'weg']] as $config) {
            try {
                $this->send($config);
                self::fail('Keine Exception: ' . json_encode($config));
            } catch (WorkflowException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $this->mailer->sent);
    }

    public function testDescribesItselfForTheEditor(): void
    {
        self::assertSame('E-Mail senden', $this->action->label());
        self::assertContains(
            ['name' => 'templateId', 'label' => 'Vorlage (optional)', 'type' => 'template-ref'],
            $this->action->configSchema(),
        );
    }
}
