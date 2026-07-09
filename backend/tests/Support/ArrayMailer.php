<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\EmailMessage;
use WorkflowEngine\Contracts\MailerInterface;

/**
 * Test-Double: sammelt versendete Mails im Speicher statt sie zu verschicken.
 *
 * @phpstan-type Mail array{to:string,from:string,cc:list<string>,bcc:list<string>,subject:string,body:string,vars:array<string,mixed>}
 */
final class ArrayMailer implements MailerInterface
{
    /** @var list<Mail> */
    private array $messages = [];

    public function send(EmailMessage $message): void
    {
        $this->messages[] = [
            'to' => $message->to,
            'from' => $message->from,
            'cc' => $message->cc,
            'bcc' => $message->bcc,
            'subject' => $message->subject,
            'body' => $message->body,
            'vars' => $message->vars,
        ];
    }

    /**
     * @return list<Mail>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return Mail|null
     */
    public function last(): ?array
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }
}
