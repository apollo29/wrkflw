<?php

declare(strict_types=1);

namespace WorkflowEngine\Action;

use WorkflowEngine\Contracts\ActionInterface;
use WorkflowEngine\Contracts\MailerInterface;
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
final class SendEmailAction implements ActionInterface
{
    public function __construct(private readonly MailerInterface $mailer)
    {
    }

    public function execute(WorkflowInstance $instance, Step $step): array
    {
        $config = $step->config;
        $to = $this->interpolate($this->stringConfig($config, 'to'), $instance->context);
        $subject = $this->interpolate($this->stringConfig($config, 'subject'), $instance->context);
        $body = $this->interpolate($this->stringConfig($config, 'body'), $instance->context);

        $this->mailer->send($to, $subject, $body, $instance->context);

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
