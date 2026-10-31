<?php

declare(strict_types=1);

namespace WorkflowEngine\Definition;

/**
 * Die drei Schritt-Arten der Engine (F-02). Workflow, Datencheck und Daten schreiben
 * sind im Editor eigene Karten, für die Engine aber automatische Schritte mit Aktion.
 */
enum StepType: string
{
    case Automatic = 'automatic';
    case Interactive = 'interactive';
    case Timer = 'timer';
}
