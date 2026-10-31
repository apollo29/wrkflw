<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/** Eine Vorlage für E-Mails (Betreff und Inhalt) oder Seiten (nur Inhalt), F-70. */
final readonly class Template
{
    public function __construct(
        public string $id,
        public string $name,
        public TemplateType $type,
        public string $subject,
        public string $body,
    ) {
    }
}
