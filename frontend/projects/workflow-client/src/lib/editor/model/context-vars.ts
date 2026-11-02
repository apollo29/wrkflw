import { EditorModel } from './definition-mapping';
import { stepKind } from './operations';

export type VariableOrigin =
  | { readonly kind: 'input' }
  | { readonly kind: 'data-check' | 'data-write' | 'workflow' | 'field'; readonly step: string };

export interface ContextVariable {
  readonly name: string;
  readonly origin: VariableOrigin;
}

function text(value: unknown, fallback: string): string {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : fallback;
}

/**
 * Kontext-Variablen mit Herkunft (9.6). Grundlage für die Anzeige in der Seitenspalte, die
 * Vorschläge im Bedingungsfeld und die Platzhalter im HTML-Editor.
 */
export function contextVariables(model: EditorModel): ContextVariable[] {
  const out: ContextVariable[] = [];
  const seen = new Set<string>();
  const add = (name: string, origin: VariableOrigin): void => {
    if (name !== '' && !seen.has(name)) {
      seen.add(name);
      out.push({ name, origin });
    }
  };

  for (const input of model.inputs) {
    add(input.name.trim(), { kind: 'input' });
  }
  for (const step of model.steps) {
    const at = { step: step.name };
    switch (stepKind(step)) {
      case 'data-check': {
        const as = text(step.config['as'], 'checkedValue');
        add(as, { kind: 'data-check', ...at });
        add(`${as}Found`, { kind: 'data-check', ...at });
        const fields = Array.isArray(step.config['fields']) ? step.config['fields'] : [];
        for (const field of fields) {
          if (typeof field === 'string' && field !== '') {
            add(`${as}_${field}`, { kind: 'data-check', ...at });
          }
        }
        break;
      }
      case 'data-write': {
        const as = text(step.config['as'], 'written');
        add(as, { kind: 'data-write', ...at });
        add(`${as}Count`, { kind: 'data-write', ...at });
        break;
      }
      case 'workflow':
        add('startedWorkflow', { kind: 'workflow', ...at });
        add('subWorkflow', { kind: 'workflow', ...at });
        break;
      case 'interactive':
        for (const field of step.fields) {
          if (field.type !== 'display') {
            add(field.name.trim(), { kind: 'field', ...at });
          }
        }
        break;
    }
  }
  return out;
}
