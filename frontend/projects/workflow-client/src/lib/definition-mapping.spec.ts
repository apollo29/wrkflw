import {
  BuilderModel,
  compileCondition,
  fromDefinition,
  orderedStepNames,
  parseCondition,
  toDefinition,
} from './definition-mapping';

describe('definition-mapping', () => {
  describe('compileCondition', () => {
    it('quotes string values', () => {
      expect(compileCondition({ field: 'plan', op: '==', value: 'enterprise' })).toBe(
        "context['plan'] == 'enterprise'",
      );
    });

    it('maps Ja/Nein and true/false to booleans', () => {
      expect(compileCondition({ field: 'acceptedTerms', op: '==', value: 'Ja' })).toBe(
        "context['acceptedTerms'] == true",
      );
      expect(compileCondition({ field: 'x', op: '!=', value: 'false' })).toBe("context['x'] != false");
    });

    it('keeps numbers bare and returns true for empty field', () => {
      expect(compileCondition({ field: 'amount', op: '>', value: '1000' })).toBe(
        "context['amount'] > 1000",
      );
      expect(compileCondition({ field: '', op: '==', value: 'x' })).toBe('true');
    });
  });

  describe('parseCondition', () => {
    it('parses string, boolean and number expressions', () => {
      expect(parseCondition("context['plan'] == 'enterprise'")).toEqual({
        field: 'plan',
        op: '==',
        value: 'enterprise',
      });
      expect(parseCondition("context['acceptedTerms'] == true")).toEqual({
        field: 'acceptedTerms',
        op: '==',
        value: 'true',
      });
      expect(parseCondition("context['amount'] > 1000")).toEqual({ field: 'amount', op: '>', value: '1000' });
    });

    it('returns null for non-matching expressions', () => {
      expect(parseCondition("context['a'] and context['b']")).toBeNull();
      expect(parseCondition('true')).toBeNull();
    });
  });

  it('round-trips a model through toDefinition/fromDefinition', () => {
    const model: BuilderModel = {
      id: 'flow',
      name: 'Flow',
      startStep: 'ask',
      steps: [
        {
          name: 'ask',
          type: 'interactive',
          action: null,
          config: {},
          title: 'Frage',
          description: 'Bitte ausfüllen',
          fields: [{ name: 'ok', label: 'OK', type: 'boolean' }],
          pageTemplateId: '',
          delaySeconds: null,
          transitions: [
            {
              to: 'done',
              event: 'submit',
              mode: 'assistant',
              condition: { field: 'ok', op: '==', value: 'Ja' },
              raw: 'true',
            },
          ],
        },
        {
          name: 'done',
          type: 'automatic',
          action: null,
          config: {},
          title: '',
          description: '',
          fields: [],
          pageTemplateId: '',
          delaySeconds: null,
          transitions: [],
        },
      ],
    };

    const json = toDefinition(model);
    const steps = json['steps'] as Record<string, Record<string, unknown>>;
    expect((steps['ask']['transitions'] as Record<string, unknown>[])[0]).toEqual({
      to: 'done',
      event: 'submit',
      when: "context['ok'] == true",
    });
    expect((steps['ask']['ui'] as Record<string, unknown>)['events']).toEqual(['submit']);

    const restored = fromDefinition(json);
    expect(restored.startStep).toBe('ask');
    const t = restored.steps[0].transitions[0];
    expect(t.mode).toBe('assistant');
    expect(t.condition).toEqual({ field: 'ok', op: '==', value: 'true' });
  });

  it('round-trips an interactive page template reference (ui.templateId)', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'ask',
      steps: {
        ask: {
          type: 'interactive',
          ui: { title: 'Hi', events: ['submit'], templateId: 'welcome-page' },
          transitions: [{ to: 'ask', event: 'submit' }],
        },
      },
    });
    expect(model.steps[0].pageTemplateId).toBe('welcome-page');

    const ui = (toDefinition(model)['steps'] as Record<string, Record<string, unknown>>)['ask'][
      'ui'
    ] as Record<string, unknown>;
    expect(ui['templateId']).toBe('welcome-page');
  });

  it('omits ui.templateId when no page template is selected', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'ask',
      steps: { ask: { type: 'interactive', ui: { events: [] }, transitions: [] } },
    });
    const ui = (toDefinition(model)['steps'] as Record<string, Record<string, unknown>>)['ask'][
      'ui'
    ] as Record<string, unknown>;
    expect('templateId' in ui).toBeFalse();
  });

  it('orders steps breadth-first from the start step', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'a',
      steps: {
        a: { type: 'automatic', transitions: [{ to: 'b' }] },
        b: { type: 'automatic', transitions: [] },
        orphan: { type: 'automatic', transitions: [] },
      },
    });
    expect(orderedStepNames(model)).toEqual(['a', 'b', 'orphan']);
  });
});
