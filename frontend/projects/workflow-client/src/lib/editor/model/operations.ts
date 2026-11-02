import { stepKindOf, StepKind } from '../../ui/step-kinds';
import { EditorModel, EditorStep, emptyStep } from './definition-mapping';

/** Aktion je Art; `null` = Art ohne feste Aktion. */
const KIND_ACTION: Record<StepKind, string | null> = {
  automatic: null,
  interactive: null,
  timer: null,
  workflow: 'start_workflow',
  'data-check': 'check_data',
  'data-write': 'write_data',
};

/** Aktionen mit eigener Karte; im Aktionsfeld «Automatisch» erscheinen sie nicht (9.14). */
export const KIND_ACTIONS: readonly string[] = ['start_workflow', 'check_data', 'write_data'];

export function stepKind(step: Pick<EditorStep, 'type' | 'action'>): StepKind {
  return stepKindOf(step);
}

export type NameError = 'empty' | 'invalid' | 'duplicate';

/** Schrittnamen sind Schlüssel im JSON und in Ausdrücken: Buchstaben, Ziffern, _ und -. */
const NAME_PATTERN = /^[A-Za-z0-9_][A-Za-z0-9_-]*$/;

export function nameError(model: EditorModel, current: string, next: string): NameError | null {
  const name = next.trim();
  if (name === '') {
    return 'empty';
  }
  if (!NAME_PATTERN.test(name)) {
    return 'invalid';
  }
  if (name !== current && model.steps.some((s) => s.name === name)) {
    return 'duplicate';
  }
  return null;
}

/** Freier Name `schritt_N` (E-30). */
export function nextStepName(model: EditorModel): string {
  const taken = new Set(model.steps.map((s) => s.name));
  let n = 1;
  while (taken.has(`schritt_${n}`)) {
    n++;
  }
  return `schritt_${n}`;
}

function newStep(name: string, kind: StepKind): EditorStep {
  const type = kind === 'interactive' || kind === 'timer' ? kind : 'automatic';
  return { ...emptyStep(name, type), action: KIND_ACTION[kind] };
}

/** Neuer Schritt am Ende; der erste wird Start-Schritt (E-30, E-31). */
export function addStep(model: EditorModel, kind: StepKind): { model: EditorModel; name: string } {
  const name = nextStepName(model);
  return {
    name,
    model: {
      ...model,
      steps: [...model.steps, newStep(name, kind)],
      startStep:
        model.startStep === '' || !model.steps.some((s) => s.name === model.startStep)
          ? name
          : model.startStep,
    },
  };
}

/**
 * Umbenennen zieht Übergänge und Start-Schritt mit (9.14). Ein ungültiger Name ändert
 * nichts; die Oberfläche zeigt {@link nameError} am Feld.
 */
export function renameStep(model: EditorModel, from: string, to: string): EditorModel {
  const name = to.trim();
  if (name === from || nameError(model, from, name) !== null) {
    return model;
  }
  return {
    ...model,
    startStep: model.startStep === from ? name : model.startStep,
    steps: model.steps.map((step) => ({
      ...step,
      name: step.name === from ? name : step.name,
      transitions: step.transitions.map((t) => (t.to === from ? { ...t, to: name } : t)),
    })),
  };
}

/** Art wechseln; Übergänge bleiben (E-34). */
export function setKind(model: EditorModel, name: string, kind: StepKind): EditorModel {
  return updateStep(model, name, (step) => {
    if (stepKind(step) === kind) {
      return step;
    }
    const fresh = newStep(step.name, kind);
    return {
      ...step,
      type: fresh.type,
      action: fresh.action,
      config: {},
      title: step.title || fresh.title,
      delaySeconds: fresh.type === 'timer' ? (step.delaySeconds ?? fresh.delaySeconds) : null,
    };
  });
}

export function updateStep(
  model: EditorModel,
  name: string,
  change: (step: EditorStep) => EditorStep,
): EditorModel {
  return { ...model, steps: model.steps.map((s) => (s.name === name ? change(s) : s)) };
}
