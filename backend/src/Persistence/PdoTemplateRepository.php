<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Contracts\Template;
use WorkflowEngine\Contracts\TemplateRepositoryInterface;
use WorkflowEngine\Contracts\TemplateType;

/** Ablage der E-Mail- und Seitenvorlagen in `wf_template`. */
final readonly class PdoTemplateRepository implements TemplateRepositoryInterface
{
    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function listTemplates(?TemplateType $type = null): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, name, type, subject, body FROM wf_template'
            . ($type === null ? '' : ' WHERE type = ?')
            . ' ORDER BY name, id',
        );
        $statement->execute($type === null ? [] : [$type->value]);

        $out = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $out[] = self::hydrate($row);
            }
        }

        return $out;
    }

    public function findTemplate(string $id): ?Template
    {
        $statement = $this->pdo->prepare('SELECT id, name, type, subject, body FROM wf_template WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? self::hydrate($row) : null;
    }

    public function saveTemplate(Template $template): void
    {
        $this->pdo->prepare(
            'INSERT INTO wf_template (id, name, type, subject, body) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type), subject = VALUES(subject), body = VALUES(body)',
        )->execute([$template->id, $template->name, $template->type->value, $template->subject, $template->body]);
    }

    public function deleteTemplate(string $id): void
    {
        $this->pdo->prepare('DELETE FROM wf_template WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function hydrate(array $row): Template
    {
        $text = static fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';

        return new Template(
            $text('id'),
            $text('name'),
            TemplateType::tryFrom($text('type')) ?? TemplateType::Email,
            $text('subject'),
            $text('body'),
        );
    }
}
