import { fromDefinition } from './definition-mapping';
import { contextVariables } from './context-vars';

describe('contextVariables (9.6)', () => {
  it('collects variables with their origin', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'laden',
      inputs: [{ name: 'antrag_id', required: true }],
      steps: {
        laden: {
          action: 'check_data',
          config: { entity: 'antrag', as: 'antrag', fields: ['betrag', 'mail'] },
          transitions: [],
        },
        pruefen: {
          type: 'interactive',
          ui: {
            fields: [
              { name: 'bemerkung', type: 'text' },
              { name: 'info', type: 'display' },
            ],
          },
          transitions: [],
        },
        speichern: { action: 'write_data', config: {}, transitions: [] },
        abschluss: { action: 'start_workflow', transitions: [] },
        ohne: { action: 'check_data', transitions: [] },
      },
    });

    expect(contextVariables(model)).toEqual([
      { name: 'antrag_id', origin: { kind: 'input' } },
      { name: 'antrag', origin: { kind: 'data-check', step: 'laden' } },
      { name: 'antragFound', origin: { kind: 'data-check', step: 'laden' } },
      { name: 'antrag_betrag', origin: { kind: 'data-check', step: 'laden' } },
      { name: 'antrag_mail', origin: { kind: 'data-check', step: 'laden' } },
      { name: 'bemerkung', origin: { kind: 'field', step: 'pruefen' } },
      { name: 'written', origin: { kind: 'data-write', step: 'speichern' } },
      { name: 'writtenCount', origin: { kind: 'data-write', step: 'speichern' } },
      { name: 'startedWorkflow', origin: { kind: 'workflow', step: 'abschluss' } },
      { name: 'subWorkflow', origin: { kind: 'workflow', step: 'abschluss' } },
      { name: 'checkedValue', origin: { kind: 'data-check', step: 'ohne' } },
      { name: 'checkedValueFound', origin: { kind: 'data-check', step: 'ohne' } },
    ]);
  });

  it('lists each name once and skips nameless entries', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'a',
      inputs: [{ name: 'x' }, { name: '' }],
      steps: {
        a: { type: 'interactive', ui: { fields: [{ name: 'x' }, { name: '' }] }, transitions: [] },
      },
    });
    expect(contextVariables(model).map((v) => v.name)).toEqual(['x']);
  });
});
