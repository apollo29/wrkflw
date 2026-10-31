<?php

declare(strict_types=1);

namespace WorkflowEngine\Instance;

/**
 * Der Zustand eines Durchlaufs (F-22). Die Werte sind dieselben wie in 2.x und so in
 * der Datenbank abgelegt.
 */
enum InstanceStatus: string
{
    case Running = 'running';
    case WaitingEvent = 'waiting_event';
    case WaitingTimer = 'waiting_timer';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** Beendet: abgeschlossen, fehlgeschlagen oder abgebrochen. */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }
}
