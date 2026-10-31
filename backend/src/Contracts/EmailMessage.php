<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/** Eine fertig aufbereitete E-Mail. Leerer Absender = Standard-Mailbox der Host-App (F-30). */
final readonly class EmailMessage
{
    /**
     * @param list<string>         $cc
     * @param list<string>         $bcc
     * @param array<string, mixed> $vars öffentlicher Kontext, ohne interne Schlüssel
     */
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public string $from = '',
        public array $cc = [],
        public array $bcc = [],
        public array $vars = [],
    ) {
    }
}
