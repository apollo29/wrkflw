<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/** Eine Datei-Prüfung der Host-App, z. B. «pdf_max_5mb». */
final readonly class UploadHandler
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description = '',
    ) {
    }
}
