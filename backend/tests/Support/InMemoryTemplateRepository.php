<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\TemplateRepositoryInterface;

/**
 * In-Memory-Fake des Template-Repositories fuer Unit-Tests.
 */
final class InMemoryTemplateRepository implements TemplateRepositoryInterface
{
    /** @var array<string,array{id:string,name:string,subject:string,body:string}> */
    private array $templates = [];

    public function listTemplates(): array
    {
        $out = [];
        foreach ($this->templates as $t) {
            $out[] = ['id' => $t['id'], 'name' => $t['name']];
        }

        return $out;
    }

    public function findTemplate(string $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    public function saveTemplate(string $id, string $name, string $subject, string $body): void
    {
        $this->templates[$id] = ['id' => $id, 'name' => $name, 'subject' => $subject, 'body' => $body];
    }
}
