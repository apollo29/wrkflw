<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Contracts\TemplateRepositoryInterface;
use WorkflowEngine\Exception\WorkflowException;

/**
 * Default-Repository fuer Templates auf Basis von PDO (MariaDB, Tabelle wf_template).
 */
final class PdoTemplateRepository implements TemplateRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function listTemplates(): array
    {
        $stmt = $this->pdo->query('SELECT id, name FROM wf_template ORDER BY name ASC');
        if ($stmt === false) {
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'id' => $this->str($row, 'id'),
                'name' => $this->str($row, 'name'),
            ];
        }

        return $out;
    }

    public function findTemplate(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, subject, body FROM wf_template WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => $this->str($row, 'id'),
            'name' => $this->str($row, 'name'),
            'subject' => $this->str($row, 'subject'),
            'body' => $this->str($row, 'body'),
        ];
    }

    public function saveTemplate(string $id, string $name, string $subject, string $body): void
    {
        $this->pdo->prepare(
            'INSERT INTO wf_template (id, name, subject, body)
             VALUES (:id, :name, :subject, :body)
             ON DUPLICATE KEY UPDATE name = VALUES(name), subject = VALUES(subject), body = VALUES(body)'
        )->execute([':id' => $id, ':name' => $name, ':subject' => $subject, ':body' => $body]);
    }

    /**
     * @param array<mixed,mixed> $row
     */
    private function str(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value)) {
            throw new WorkflowException("Spalte '{$key}' fehlt oder ist kein String.");
        }

        return $value;
    }
}
