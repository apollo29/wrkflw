import { fromDefinition } from './definition-mapping';
import { addStep, nameError, renameStep, setKind, stepKind } from './operations';

const base = () =>
  fromDefinition({
    id: 'f',
    startStep: 'a',
    steps: {
      a: { type: 'interactive', transitions: [{ to: 'b', event: 'submit', label: 'Weiter' }] },
      b: {
        type: 'automatic',
        action: 'check_data',
        config: { entity: 'antrag' },
        transitions: [{ to: 'a', when: "context['x'] == 1" }, { to: 'c' }],
      },
      c: { type: 'timer', delaySeconds: 60, transitions: [] },
    },
  });

describe('operations', () => {
  describe('addStep', () => {
    it('names steps schritt_N, makes the first one start and fills defaults (E-30, E-31)', () => {
      let model = fromDefinition({ id: 'n', steps: {} });
      let added = addStep(model, 'interactive');
      model = added.model;
      expect(added.name).toBe('schritt_1');
      expect(model.startStep).toBe('schritt_1');
      expect(model.steps[0]).toMatchObject({ type: 'interactive', title: 'schritt_1' });

      added = addStep(model, 'timer');
      expect(added.name).toBe('schritt_2');
      expect(added.model.startStep).toBe('schritt_1');
      expect(added.model.steps[1]).toMatchObject({ type: 'timer', delaySeconds: 3600 });
    });

    it('skips names that are taken and maps the kinds to type and action', () => {
      let model = fromDefinition({
        id: 'n',
        startStep: 'schritt_2',
        steps: { schritt_2: { transitions: [] } },
      });
      const added = addStep(model, 'data-write');
      model = added.model;
      expect(added.name).toBe('schritt_1');
      expect(addStep(model, 'workflow').name).toBe('schritt_3');
      expect(model.steps.find((s) => s.name === 'schritt_1')).toMatchObject({
        type: 'automatic',
        action: 'write_data',
      });
      expect(stepKind(addStep(model, 'data-check').model.steps[2]!)).toBe('data-check');
    });
  });

  describe('renameStep', () => {
    it('carries transitions and the start step along (9.14)', () => {
      const renamed = renameStep(base(), 'a', 'erfassen');
      expect(renamed.startStep).toBe('erfassen');
      expect(renamed.steps.map((s) => s.name)).toEqual(['erfassen', 'b', 'c']);
      expect(renamed.steps[1]?.transitions[0]?.to).toBe('erfassen');
    });

    it('refuses empty, invalid and duplicate names', () => {
      const model = base();
      expect(nameError(model, 'a', 'b')).toBe('duplicate');
      expect(nameError(model, 'a', '  ')).toBe('empty');
      expect(nameError(model, 'a', 'mit leerzeichen')).toBe('invalid');
      expect(nameError(model, 'a', 'a')).toBeNull();
      expect(nameError(model, 'a', 'neu_1')).toBeNull();
      expect(renameStep(model, 'a', 'b')).toBe(model);
    });
  });

  describe('setKind', () => {
    it('keeps transitions and switches type and action (E-34)', () => {
      const model = setKind(base(), 'b', 'workflow');
      const step = model.steps[1]!;
      expect(step).toMatchObject({ type: 'automatic', action: 'start_workflow', config: {} });
      expect(step.transitions.map((t) => t.to)).toEqual(['a', 'c']);
      expect(stepKind(step)).toBe('workflow');
    });

    it('gives a new interactive step a title and a timer one hour', () => {
      const interactive = setKind(base(), 'c', 'interactive').steps[2]!;
      expect(interactive).toMatchObject({ type: 'interactive', title: 'c', delaySeconds: null });
      const timer = setKind(base(), 'a', 'timer').steps[0]!;
      expect(timer).toMatchObject({ type: 'timer', delaySeconds: 3600 });
    });

    it('keeps the action config when the kind does not change', () => {
      expect(setKind(base(), 'b', 'data-check').steps[1]?.config).toEqual({ entity: 'antrag' });
    });
  });
});
