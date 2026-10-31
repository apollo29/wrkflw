<?php

declare(strict_types=1);

namespace WorkflowEngine\Contracts;

/**
 * Markiert Fehler, die eine Wiederholung nicht behebt: ungültige Werte, verletzte Regeln,
 * fehlende Freigaben, unbekannte Modelle oder Felder. Die Engine lässt den Durchlauf dann
 * sofort fehlschlagen, statt es nach Q-01 erneut zu versuchen. Technische Fehler wie eine
 * Zeitüberschreitung tragen diese Markierung nicht und werden wiederholt (F-132).
 */
interface PermanentFailureInterface extends \Throwable
{
}
