<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: E-Mail-Versand. Die Host-App liefert die konkrete Implementierung
 * (z. B. ein Adapter auf Symfony Mailer, PHPMailer, eine Queue ...).
 */
interface MailerInterface
{
    /**
     * @param array<string,mixed> $vars Template-Variablen (i. d. R. der Instanz-Kontext)
     */
    public function send(
        string $to,
        string $subject,
        string $body,
        array $vars = [],
    ): void;
}
