<?php

declare(strict_types=1);

namespace WorkflowEngine\Model;

/**
 * Eine Regel der Host-App, die vor jedem Speichern läuft (F-125 bis F-127). Sie gilt für
 * jeden Ablauf und lässt sich im Editor nicht umgehen. Sie kann Werte prüfen und je Feld
 * Fehler melden oder Werte ergänzen, etwa einen Änderungszeitpunkt.
 */
interface RuleInterface
{
    public function beforeSave(Change $change): void;
}
