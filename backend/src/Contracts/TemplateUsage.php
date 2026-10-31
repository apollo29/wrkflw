<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/** Ein Schritt, der eine Vorlage verwendet (F-71). */
final readonly class TemplateUsage
{
    public function __construct(
        public string $definitionId,
        public int $version,
        public string $step,
    ) {
    }
}
