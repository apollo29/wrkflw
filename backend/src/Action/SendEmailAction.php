<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ActionResult;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\EmailMessage;
use WorkflowEngine\Contracts\MailerInterface;
use WorkflowEngine\Contracts\TemplateRepositoryInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Engine\PlaceholderRenderer;
use WorkflowEngine\Exception\WorkflowException;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Versendet eine E-Mail (F-30, F-31). Eine Vorlage überschreibt Betreff und Inhalt;
 * ohne Absender gilt die Standard-Mailbox der Host-App.
 */
final readonly class SendEmailAction implements ActionInterface, ConfigurableActionInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private ?TemplateRepositoryInterface $templates = null,
        private PlaceholderRenderer $renderer = new PlaceholderRenderer(),
    ) {
    }

    public function label(): string
    {
        return 'E-Mail senden';
    }

    public function configSchema(): array
    {
        return [
            ['name' => 'templateId', 'label' => 'Vorlage (optional)', 'type' => 'template-ref'],
            ['name' => 'to', 'label' => 'An', 'type' => 'text'],
            ['name' => 'from', 'label' => 'Absender (leer = Standard)', 'type' => 'text'],
            ['name' => 'cc', 'label' => 'CC (mit Komma getrennt)', 'type' => 'text'],
            ['name' => 'bcc', 'label' => 'BCC (mit Komma getrennt)', 'type' => 'text'],
            ['name' => 'subject', 'label' => 'Betreff', 'type' => 'text'],
            ['name' => 'body', 'label' => 'Inhalt (HTML)', 'type' => 'html'],
        ];
    }

    public function execute(WorkflowInstance $instance, Step $step): ActionResult
    {
        $context = $instance->publicContext();
        $render = fn (string $key): string => trim($this->renderer->render(self::text($step->config, $key), $context));

        $to = $render('to');
        if ($to === '') {
            throw new WorkflowException("Schritt '{$step->name}': die E-Mail hat keinen Empfänger.");
        }

        $subject = self::text($step->config, 'subject');
        $body = self::text($step->config, 'body');
        $templateId = self::text($step->config, 'templateId');
        if ($templateId !== '') {
            $template = $this->templates?->findTemplate($templateId)
                ?? throw new WorkflowException("Schritt '{$step->name}': Vorlage '{$templateId}' nicht gefunden.");
            $subject = $template->subject;
            $body = $template->body;
        }

        $this->mailer->send(new EmailMessage(
            to: $to,
            subject: $this->renderer->render($subject, $context),
            body: $this->renderer->render($body, $context),
            from: $render('from'),
            cc: $this->addresses(self::text($step->config, 'cc'), $context),
            bcc: $this->addresses(self::text($step->config, 'bcc'), $context),
            vars: $context,
        ));

        return ActionResult::of(['lastEmailTo' => $to]);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return list<string>
     */
    private function addresses(string $raw, array $context): array
    {
        $out = [];
        foreach (preg_split('/[,;]/', $this->renderer->render($raw, $context)) ?: [] as $part) {
            $address = trim($part);
            if ($address !== '') {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function text(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
