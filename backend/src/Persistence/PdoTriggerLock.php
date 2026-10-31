<?php

declare(strict_types=1);

namespace WorkflowEngine\Persistence;

use WorkflowEngine\Contracts\TriggerLockInterface;

/**
 * Sperrfrist für Trigger in `wf_trigger_lock` (F-143).
 *
 * Belegt atomar mit einer Anweisung: eine neue Zeile, oder eine bestehende, deren Frist
 * abgelaufen ist. Ob belegt wurde, zeigt die Zahl der geänderten Zeilen (1 = eingefügt,
 * 2 = erneuert, 0 = noch gesperrt). So können parallele Läufe nicht beide starten.
 */
final readonly class PdoTriggerLock implements TriggerLockInterface
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function __construct(private \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function acquire(string $triggerKey, string $subjectKey, \DateTimeImmutable $now, \DateTimeImmutable $until): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO wf_trigger_lock (trigger_key, subject_key, locked_until) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE locked_until = IF(locked_until <= ?, VALUES(locked_until), locked_until)',
        );
        $statement->execute([
            $triggerKey,
            $subjectKey,
            $until->setTimezone($now->getTimezone())->format(self::FORMAT),
            $now->format(self::FORMAT),
        ]);

        return $statement->rowCount() > 0;
    }

    public function release(string $triggerKey, string $subjectKey): void
    {
        $this->pdo->prepare('DELETE FROM wf_trigger_lock WHERE trigger_key = ? AND subject_key = ?')->execute([$triggerKey, $subjectKey]);
    }
}
