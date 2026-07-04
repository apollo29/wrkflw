<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\ConfigurableActionInterface;
use WorkflowEngine\Contracts\MailerInterface;
use WorkflowEngine\Contracts\TemplateRepositoryInterface;
use WorkflowEngine\Definition\Step;
use WorkflowEngine\Instance\WorkflowInstance;

/**
 * Eingebaute Aktion zum E-Mail-Versand.
 *
 * Step-Konfiguration:
 *   "action": "send_email",
 *   "config": {
 *       "to":      "{{email}}",          // {{key}} wird aus dem Kontext ersetzt
 *       "subject": "Willkommen, {{name}}",
 *       "body":    "Hallo {{name}}, ..."
 *   }
 */
final class SendEmailAction implements ActionInterface, ConfigurableActionInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ?TemplateRepositoryInterface $templates = null,
    ) {
    }

    public function configSchema(): array
    {
        return [
            // Referenz auf ein wiederverwendbares Template; ist es gesetzt und
            // auffindbar, liefert es Betreff + Body (die inline-Felder dienen dann
            // nur als Fallback).
            ['name' => 'templateId', 'label' => 'Vorlage (optional)', 'type' => 'template-ref'],
            ['name' => 'to', 'label' => 'An', 'type' => 'text'],
            ['name' => 'subject', 'label' => 'Betreff', 'type' => 'text'],
            // 'html' signalisiert dem Editor einen WYSIWYG-/Template-Editor; der
            // Body ist ein HTML-Template mit {{platzhalter}} aus dem Kontext.
            ['name' => 'body', 'label' => 'Inhalt (HTML)', 'type' => 'html'],
        ];
    }

    public function execute(WorkflowInstance $instance, Step $step): array
    {
        $config = $step->config;
        $to = $this->interpolate($this->stringConfig($config, 'to'), $instance->context);

        $subject = $this->stringConfig($config, 'subject');
        $body = $this->stringConfig($config, 'body');

        // Referenziertes Template hat Vorrang (zentrale Pflege).
        $templateId = $this->stringConfig($config, 'templateId');
        if ($templateId !== '' && $this->templates !== null) {
            $template = $this->templates->findTemplate($templateId);
            if ($template !== null) {
                $subject = $template['subject'];
                $body = $template['body'];
            }
        }

        $this->mailer->send(
            $to,
            $this->interpolate($subject, $instance->context),
            $this->interpolate($body, $instance->context),
            $instance->context,
        );

        return ['lastEmailTo' => $to];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function stringConfig(array $config, string $key): string
    {
        $value = $config[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Ersetzt {{key}}-Platzhalter durch Kontextwerte. Fehlende oder nicht
     * darstellbare Werte werden zu einem leeren String.
     *
     * @param array<string,mixed> $context
     */
    private function interpolate(string $template, array $context): string
    {
        return preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            static function (array $m) use ($context): string {
                $value = $context[$m[1]] ?? '';
                if (is_scalar($value) || $value instanceof \Stringable) {
                    return (string) $value;
                }

                return '';
            },
            $template,
        ) ?? $template;
    }
}
