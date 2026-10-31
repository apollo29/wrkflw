<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateRepositoryInterface;
use WorkflowEngine\Contracts\TemplateType;

final class InMemoryTemplateRepository implements TemplateRepositoryInterface
{
    /** @var array<string, Template> */
    private array $templates = [];

    public function listTemplates(?TemplateType $type = null): array
    {
        return array_values(array_filter(
            $this->templates,
            static fn (Template $t): bool => $type === null || $t->type === $type,
        ));
    }

    public function findTemplate(string $id): ?Template
    {
        return $this->templates[$id] ?? null;
    }

    public function saveTemplate(Template $template): void
    {
        $this->templates[$template->id] = $template;
    }

    public function deleteTemplate(string $id): void
    {
        unset($this->templates[$id]);
    }
}
