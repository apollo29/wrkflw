<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * PORT: E-Mail-Versand. Die Host-App liefert die konkrete Implementierung
 * (z. B. ein Adapter auf Symfony Mailer, PHPMailer, eine Queue ...).
 *
 * Die Nachricht wird als {@see EmailMessage} uebergeben (to/from/cc/bcc/subject/body/vars).
 */
interface MailerInterface
{
    public function send(EmailMessage $message): void;
}
