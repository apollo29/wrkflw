<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\EmailMessage;
use WorkflowEngine\Contracts\MailerInterface;

/** Sammelt versendete Mails; kann eine Anzahl Fehlschläge vortäuschen. */
final class ArrayMailer implements MailerInterface
{
    /** @var list<EmailMessage> */
    public array $sent = [];

    public function __construct(private int $failuresBeforeSuccess = 0)
    {
    }

    public function failNext(int $times): void
    {
        $this->failuresBeforeSuccess = $times;
    }

    public function last(): EmailMessage
    {
        return $this->sent[count($this->sent) - 1] ?? throw new \LogicException('Keine Mail versendet.');
    }

    public function send(EmailMessage $message): void
    {
        if ($this->failuresBeforeSuccess > 0) {
            $this->failuresBeforeSuccess--;
            throw new \RuntimeException('Mailversand: Zeitüberschreitung.');
        }
        $this->sent[] = $message;
    }
}
