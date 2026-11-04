import type { CurrentStep, JsonObject } from '../api/models';

export type RunnerFieldType = 'text' | 'boolean' | 'file' | 'display';

export interface RunnerField {
  readonly name: string;
  readonly label: string;
  readonly type: RunnerFieldType;
  /** Datei-Prüfung der Host-App (nur bei `file`). */
  readonly handler: string | null;
}

export interface StepUi {
  readonly title: string;
  readonly description: string;
  readonly template: string | null;
  readonly fields: readonly RunnerField[];
}

export type RunnerValues = Readonly<Record<string, string | boolean>>;

export type RunnerState = 'loading' | 'interactive' | 'running' | 'waiting' | 'finished';

const TYPES: readonly RunnerFieldType[] = ['text', 'boolean', 'file', 'display'];

const str = (value: unknown): string => (typeof value === 'string' ? value : '');

/** Die Oberfläche, die ein Schritt selbst beschreibt (F-50); fremde Formen werden übergangen. */
export function parseStepUi(ui: JsonObject | null | undefined): StepUi {
  const data = ui && typeof ui === 'object' ? ui : {};
  const raw = Array.isArray(data['fields']) ? (data['fields'] as unknown[]) : [];
  const fields: RunnerField[] = [];
  for (const entry of raw) {
    if (!entry || typeof entry !== 'object') {
      continue;
    }
    const f = entry as Record<string, unknown>;
    const name = str(f['name']).trim();
    if (name === '' || name.startsWith('__')) {
      continue;
    }
    const type = TYPES.includes(f['type'] as RunnerFieldType)
      ? (f['type'] as RunnerFieldType)
      : 'text';
    fields.push({
      name,
      label: str(f['label']).trim() || name,
      type,
      handler: type === 'file' ? str(f['handler']) || null : null,
    });
  }
  return {
    title: str(data['title']),
    description: str(data['description']),
    template: str(data['template']) || str(data['templateId']) || null,
    fields,
  };
}

function truthy(value: unknown): boolean {
  if (typeof value === 'string') {
    return ['1', 'true', 'ja', 'wahr', 'yes'].includes(value.trim().toLowerCase());
  }
  return value === true || value === 1;
}

/** Vorbelegung aus dem Kontext, damit «zurück» die Eingaben behält. */
export function initialValues(
  fields: readonly RunnerField[],
  context: JsonObject,
): Record<string, string | boolean> {
  const out: Record<string, string | boolean> = {};
  for (const f of fields) {
    const value = context[f.name];
    if (f.type === 'display' || value === undefined || value === null) {
      continue;
    }
    if (f.type === 'boolean') {
      out[f.name] = truthy(value);
    } else if (typeof value === 'string' || typeof value === 'number') {
      out[f.name] = String(value);
    }
  }
  return out;
}

/** Was mit dem Ereignis geht: nur Eingabefelder (F-51); Datei-Felder nur mit Wert. */
export function payloadFor(
  fields: readonly RunnerField[],
  values: RunnerValues,
): Record<string, string | boolean> {
  const out: Record<string, string | boolean> = {};
  for (const f of fields) {
    const value = values[f.name];
    switch (f.type) {
      case 'display':
        break;
      case 'boolean':
        out[f.name] = value === true;
        break;
      case 'file':
        if (typeof value === 'string' && value !== '') {
          out[f.name] = value;
        }
        break;
      default:
        out[f.name] = typeof value === 'string' ? value : '';
    }
  }
  return out;
}

const ESCAPES: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
};

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (c) => ESCAPES[c] ?? c);
}

/** Seitenvorlage mit Platzhaltern; Werte werden escaped (F-53). */
export function renderTemplate(html: string, context: JsonObject): string {
  return html.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, key: string) => {
    const value = context[key];
    return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean'
      ? escapeHtml(String(value))
      : '';
  });
}

/** Anzeige-Feld: Text, Zahl oder Ja/Nein; sonst nichts (Objekte nicht roh zeigen). */
export function displayValue(value: unknown): string | boolean | null {
  if (typeof value === 'boolean') {
    return value;
  }
  if (typeof value === 'number') {
    return String(value);
  }
  return typeof value === 'string' && value !== '' ? value : null;
}

/** Zustand für die Anzeige (F-22, F-23). */
export function runnerState(step: CurrentStep | null): RunnerState {
  if (step === null) {
    return 'loading';
  }
  if (step.finished) {
    return 'finished';
  }
  if (step.interactive && step.status === 'waiting_event') {
    return 'interactive';
  }
  return step.status === 'running' ? 'running' : 'waiting';
}

/** Schlüssel für ein Ereignis (Q-04). */
export function newEventKey(): string {
  const c = globalThis.crypto as Crypto | undefined;
  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID();
  }
  return `ev-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
