<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Wert-Objekt einer zu versendenden E-Mail. Wird an {@see MailerInterface::send()}
 * uebergeben. Ein leeres $from bedeutet: der Host-Mailer nutzt seine Standard-Mailbox.
 */
final class EmailMessage
{
    /**
     * @param list<string>        $cc
     * @param list<string>        $bcc
     * @param array<string,mixed> $vars Template-Variablen (i. d. R. der Instanz-Kontext)
     */
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly string $from = '',
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $vars = [],
    ) {
    }
}
