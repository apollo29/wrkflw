import { EditorModel } from './definition-mapping';
import { stepKind } from './operations';

export type CheckCode =
  | 'id_missing'
  | 'start_step_missing'
  | 'target_unknown'
  | 'interactive_without_event'
  | 'action_missing'
  | 'action_unknown'
  | 'file_without_handler';

export interface LocalCheck {
  readonly code: CheckCode;
  readonly step: string | null;
  readonly transition?: number;
  readonly field?: number;
  /** `hint` hindert das Speichern nicht. */
  readonly severity: 'error' | 'hint';
}

export interface CheckContext {
  /** Kennt der Katalog diese Aktion? */
  hasAction(key: string): boolean;
}

/**
 * Sofortige Prüfungen im Browser, ergänzend zur Prüfung der Engine (`/workflows/validate`).
 * Sie decken ab, was ohne Server sofort am Schritt sichtbar sein soll (9.14).
 */
export function localChecks(model: EditorModel, ctx: CheckContext): LocalCheck[] {
  const out: LocalCheck[] = [];
  const names = new Set(model.steps.map((s) => s.name));

  if (model.id.trim() === '') {
    out.push({ code: 'id_missing', step: null, severity: 'error' });
  }
  if (model.steps.length > 0 && !names.has(model.startStep)) {
    out.push({ code: 'start_step_missing', step: null, severity: 'error' });
  }

  for (const step of model.steps) {
    step.transitions.forEach((t, index) => {
      if (!names.has(t.to)) {
        out.push({ code: 'target_unknown', step: step.name, transition: index, severity: 'error' });
      }
    });
    const kind = stepKind(step);
    if (kind === 'interactive') {
      if (step.transitions.length > 0 && !step.transitions.some((t) => (t.event ?? '') !== '')) {
        out.push({ code: 'interactive_without_event', step: step.name, severity: 'error' });
      }
      step.fields.forEach((field, index) => {
        if (field.type === 'file' && (field.handler ?? '') === '') {
          out.push({
            code: 'file_without_handler',
            step: step.name,
            field: index,
            severity: 'hint',
          });
        }
      });
    } else if (step.type === 'automatic') {
      if (step.action === null || step.action === '') {
        // Ohne Ausgang ist ein automatischer Schritt ohne Aktion das Ende des Ablaufs;
        // die Engine nimmt ihn so an (Abläufe aus 2.x enden mit `done`).
        if (step.transitions.length > 0) {
          out.push({ code: 'action_missing', step: step.name, severity: 'error' });
        }
      } else if (!ctx.hasAction(step.action)) {
        out.push({ code: 'action_unknown', step: step.name, severity: 'error' });
      }
    }
  }
  return out;
}
