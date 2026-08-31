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

  // Eine neue Engine-Version darf keinen leeren Kasten hinterlassen: dann
  // lieber der rohe Wert als gar nichts.
  it('fällt bei einem unbekannten Status auf den Rohwert zurück', () => {
    expect(workflowStatusLabel('cancelled')).toBe('cancelled');
    expect(workflowWaitingText('cancelled')).toBe('cancelled');
    expect(workflowFinishedText('cancelled')).toContain('cancelled');
  });
});
