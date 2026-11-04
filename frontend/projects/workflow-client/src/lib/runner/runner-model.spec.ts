import type { CurrentStep } from '../api/models';
import {
  displayValue,
  initialValues,
  parseStepUi,
  payloadFor,
  renderTemplate,
  runnerState,
} from './runner-model';

const step = (patch: Partial<CurrentStep>): CurrentStep => ({
  instanceId: 'i',
  status: 'waiting_event',
  step: 'pruefen',
  type: 'interactive',
  interactive: true,
  finished: false,
  ui: {},
  events: [],
  canGoBack: false,
  context: {},
  ...patch,
});

describe('runner-model', () => {
  it('parses the step ui robustly (F-50)', () => {
    expect(
      parseStepUi({
        title: 'Prüfen',
        description: 7,
        templateId: 'alt',
        fields: [
          { name: 'a', label: 'A', type: 'boolean' },
          { name: 'b' },
          { label: 'ohne Name' },
          'kaputt',
          { name: 'f', type: 'file', handler: 'pdf' },
        ],
      }),
    ).toEqual({
      title: 'Prüfen',
      description: '',
      template: 'alt',
      fields: [
        { name: 'a', label: 'A', type: 'boolean', handler: null },
        { name: 'b', label: 'b', type: 'text', handler: null },
        { name: 'f', label: 'f', type: 'file', handler: 'pdf' },
      ],
    });
    expect(parseStepUi({ template: 'neu', templateId: 'alt' }).template).toBe('neu');
    expect(parseStepUi(null as never)).toEqual({
      title: '',
      description: '',
      template: null,
      fields: [],
    });
  });

  it('prefills from the context and sends input fields only (F-51)', () => {
    const fields = parseStepUi({
      fields: [
        { name: 'bemerkung' },
        { name: 'ok', type: 'boolean' },
        { name: 'betrag', type: 'display' },
        { name: 'beleg', type: 'file' },
      ],
    }).fields;
    const values = initialValues(fields, {
      bemerkung: 'alt',
      ok: 'ja',
      betrag: 1250,
      beleg: 'datei-1',
    });
    expect(values).toEqual({ bemerkung: 'alt', ok: true, beleg: 'datei-1' });
    expect(payloadFor(fields, { ...values, betrag: 'nicht senden', ok: false })).toEqual({
      bemerkung: 'alt',
      ok: false,
      beleg: 'datei-1',
    });
    expect(payloadFor(fields, {})).toEqual({ bemerkung: '', ok: false });
  });

  it('escapes context values in page templates (F-53)', () => {
    expect(
      renderTemplate('<p>Hallo {{ name }}, {{betrag}} {{fehlt}}</p>', {
        name: '<b>Anna</b>',
        betrag: 12,
      }),
    ).toBe('<p>Hallo &lt;b&gt;Anna&lt;/b&gt;, 12 </p>');
  });

  it('shows display values readably', () => {
    expect(displayValue('x')).toBe('x');
    expect(displayValue(12)).toBe('12');
    expect(displayValue(true)).toBe(true);
    expect(displayValue(null)).toBeNull();
    expect(displayValue('')).toBeNull();
    expect(displayValue({ a: 1 })).toBeNull();
  });

  it('derives the runner state (F-22, F-23)', () => {
    expect(runnerState(null)).toBe('loading');
    expect(runnerState(step({}))).toBe('interactive');
    expect(runnerState(step({ status: 'running', interactive: false }))).toBe('running');
    expect(runnerState(step({ status: 'waiting_timer', interactive: false, type: 'timer' }))).toBe(
      'waiting',
    );
    expect(
      runnerState(step({ status: 'waiting_event', interactive: false, type: 'automatic' })),
    ).toBe('waiting');
    expect(runnerState(step({ status: 'completed', finished: true }))).toBe('finished');
  });
});
