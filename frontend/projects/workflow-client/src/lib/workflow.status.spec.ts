import {
  WORKFLOW_STATUS_LABELS,
  workflowFinishedText,
  workflowStatusLabel,
  workflowWaitingText,
} from './workflow.status';

describe('workflow status labels', () => {
  it('übersetzt jeden Status in etwas Lesbares', () => {
    for (const [status, label] of Object.entries(WORKFLOW_STATUS_LABELS)) {
      expect(label).not.toContain('_');
      expect(workflowStatusLabel(status)).toBe(label);
    }
  });

  it('sagt beim Warten, worauf gewartet wird', () => {
    expect(workflowWaitingText('waiting_timer')).toContain('Termin');
    expect(workflowWaitingText('waiting_event')).toContain('Rückmeldung');
    expect(workflowWaitingText('running')).toContain('Hintergrund');
  });

  it('unterscheidet abgeschlossen von abgebrochen', () => {
    expect(workflowFinishedText('completed')).toContain('abgeschlossen');
    expect(workflowFinishedText('failed')).toContain('abgebrochen');
  });

  // `cancelled` war bis zum Rücksprung über die Ablauf-Grenze der Platzhalter
  // für «unbekannter Status» in diesem Test. Jetzt ist er ein echter
  // Endzustand: jemand ist aus dem Ablauf herausgegangen, bevor er zu Ende war.
  it('nennt einen abgebrochenen Ablauf beim Namen', () => {
    expect(workflowStatusLabel('cancelled')).toBe('Abgebrochen');
    expect(workflowWaitingText('cancelled')).toContain('zurückgegangen');
    expect(workflowFinishedText('cancelled')).toContain('abgebrochen');
  });

  // Eine neue Engine-Version darf keinen leeren Kasten hinterlassen: dann
  // lieber der rohe Wert als gar nichts.
  it('fällt bei einem unbekannten Status auf den Rohwert zurück', () => {
    expect(workflowStatusLabel('quantenverschraenkt')).toBe('quantenverschraenkt');
    expect(workflowWaitingText('quantenverschraenkt')).toBe('quantenverschraenkt');
    expect(workflowFinishedText('quantenverschraenkt')).toContain('quantenverschraenkt');
  });
});
