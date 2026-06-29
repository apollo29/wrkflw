<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\MailerInterface;

/**
 * Test-Double: sammelt versendete Mails im Speicher statt sie zu verschicken.
 */
final class ArrayMailer implements MailerInterface
{
    /** @var list<array{to:string,subject:string,body:string,vars:array<string,mixed>}> */
    private array $messages = [];

    public function send(string $to, string $subject, string $body, array $vars = []): void
    {
        $this->messages[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'vars' => $vars,
        ];
    }

    /**
     * @return list<array{to:string,subject:string,body:string,vars:array<string,mixed>}>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return array{to:string,subject:string,body:string,vars:array<string,mixed>}|null
     */
    public function last(): ?array
    {
        return $this->messages === [] ? null : $this->messages[array_key_last($this->messages)];
    }
}
