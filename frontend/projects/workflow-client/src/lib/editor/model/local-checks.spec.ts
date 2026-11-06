import { fromDefinition } from './definition-mapping';
import { localChecks } from './local-checks';

describe('localChecks', () => {
  it('finds problems per step right away', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'fehlt',
      steps: {
        frage: {
          type: 'interactive',
          ui: { fields: [{ name: 'datei', type: 'file' }] },
          transitions: [{ to: 'weiter' }, { to: 'nirgends', event: 'x' }],
        },
        weiter: { type: 'automatic', transitions: [{ to: 'ende' }] },
        ende: { type: 'automatic', transitions: [] },
      },
    });
    expect(localChecks(model, { hasAction: () => true })).toEqual([
      { code: 'start_step_missing', step: null, severity: 'error' },
      { code: 'target_unknown', step: 'frage', transition: 1, severity: 'error' },
      { code: 'file_without_handler', step: 'frage', field: 0, severity: 'hint' },
      { code: 'action_missing', step: 'weiter', severity: 'error' },
    ]);
  });

  // Die Engine nimmt einen automatischen Schritt ohne Aktion und ohne Ausgang als
  // Ende; Abläufe aus 2.x enden so (`done`). Als Fehler liessen sie sich nicht
  // aktiv speichern.
  it('accepts an automatic end step without an action', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'mail',
      steps: {
        mail: { action: 'send_email', transitions: [{ to: 'done' }] },
        done: { type: 'automatic', transitions: [] },
      },
    });
    expect(localChecks(model, { hasAction: () => true })).toEqual([]);
  });

  it('checks the id and unknown actions, and is quiet for a clean model', () => {
    const clean = fromDefinition({
      id: 'ok',
      startStep: 'a',
      steps: {
        a: { type: 'interactive', transitions: [{ to: 'b', event: 'submit' }] },
        b: { action: 'send_email', transitions: [] },
      },
    });
    expect(localChecks(clean, { hasAction: () => true })).toEqual([]);
    expect(localChecks({ ...clean, id: '' }, { hasAction: () => true })).toEqual([
      { code: 'id_missing', step: null, severity: 'error' },
    ]);
    expect(localChecks(clean, { hasAction: () => false })).toEqual([
      { code: 'action_unknown', step: 'b', severity: 'error' },
    ]);
  });

  it('reports an interactive step whose exits have no button', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'a',
      steps: {
        a: { type: 'interactive', transitions: [{ to: 'b' }] },
        b: { action: 'x', transitions: [] },
      },
    });
    expect(localChecks(model, { hasAction: () => true })).toEqual([
      { code: 'interactive_without_event', step: 'a', severity: 'error' },
    ]);
  });
});
