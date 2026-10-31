<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/** Ablage der E-Mail- und Seitenvorlagen. */
interface TemplateRepositoryInterface
{
    /**
     * @return list<Template>
     */
    public function listTemplates(?TemplateType $type = null): array;

    public function findTemplate(string $id): ?Template;

    public function saveTemplate(Template $template): void;

    public function deleteTemplate(string $id): void;
}
