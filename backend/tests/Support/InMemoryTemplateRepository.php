<?php

declare(strict_types=1);

namespace WorkflowEngine\Tests\Support;

use WorkflowEngine\Contracts\TemplateRepositoryInterface;

/**
 * In-Memory-Fake des Template-Repositories fuer Unit-Tests.
 */
final class InMemoryTemplateRepository implements TemplateRepositoryInterface
{
    /** @var array<string,array{id:string,name:string,type:string,subject:string,body:string}> */
    private array $templates = [];

    public function listTemplates(?string $type = null): array
    {
        $out = [];
        foreach ($this->templates as $t) {
            if ($type !== null && $t['type'] !== $type) {
                continue;
            }
            $out[] = ['id' => $t['id'], 'name' => $t['name'], 'type' => $t['type']];
        }

        return $out;
    }

    public function findTemplate(string $id): ?array
    {
        return $this->templates[$id] ?? null;
    }

    public function saveTemplate(
        string $id,
        string $name,
        string $subject,
        string $body,
        string $type = 'email',
    ): void {
        $this->templates[$id] = [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    public function deleteTemplate(string $id): void
    {
        unset($this->templates[$id]);
    }
}
