<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/** Versand von E-Mails. Wie versendet wird, entscheidet die Host-App. */
interface MailerInterface
{
    public function send(EmailMessage $message): void;
}
