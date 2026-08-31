import { WorkflowStatus } from './workflow.models';

/**
 * Lesbare Bezeichnungen der Instanz-Status.
 *
 * Die rohen Werte (`waiting_timer`) sind Feldnamen aus der Datenbank und
 * gehören nicht auf einen Bildschirm, den jemand benutzt: «Status:
 * waiting_timer» sagt der Person davor nichts darüber, ob sie etwas tun muss
 * oder nicht. Genau das ist aber die einzige Frage, die sie hat.
 */
export const WORKFLOW_STATUS_LABELS: Readonly<Record<WorkflowStatus, string>> = {
  running: 'Läuft',
  waiting_event: 'Wartet auf eine Rückmeldung',
  waiting_timer: 'Wartet auf den nächsten Termin',
  completed: 'Abgeschlossen',
  failed: 'Fehlgeschlagen',
};

/**
 * Ganze Sätze für den Runner, der nur einen einzigen Zustand zeigt und daher
 * Platz für den Grund hat — anders als eine Chip-Beschriftung in einer Liste.
 */
const WAITING_TEXTS: Readonly<Record<WorkflowStatus, string>> = {
  running: 'Läuft im Hintergrund …',
  waiting_event: 'Wartet auf eine Rückmeldung von aussen — hier ist gerade nichts zu tun.',
  waiting_timer: 'Wartet auf den nächsten Termin — hier ist gerade nichts zu tun.',
  completed: 'Abgeschlossen.',
  failed: 'Abgebrochen.',
};

/**
 * Ein unbekannter Status fällt auf den rohen Wert zurück. Das ist hässlich,
 * aber ehrlich: eine neue Engine-Version soll hier keinen leeren Kasten
 * hinterlassen.
 */
export function workflowStatusLabel(status: string): string {
  return WORKFLOW_STATUS_LABELS[status as WorkflowStatus] ?? status;
}

/** Der Wartezustand des Runners, als ganzer Satz. */
export function workflowWaitingText(status: string): string {
  return WAITING_TEXTS[status as WorkflowStatus] ?? workflowStatusLabel(status);
}

/** Der Abschlusszustand des Runners, als ganzer Satz. */
export function workflowFinishedText(status: string): string {
  if (status === 'failed') {
    return 'Der Ablauf ist abgebrochen.';
  }
  if (status === 'completed') {
    return 'Der Ablauf ist abgeschlossen.';
  }

  return `Der Ablauf ist beendet: ${workflowStatusLabel(status)}.`;
}
