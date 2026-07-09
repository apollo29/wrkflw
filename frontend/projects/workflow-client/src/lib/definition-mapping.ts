import { StepType } from './workflow.models';

/**
 * Reine Abbildungslogik zwischen dem editierbaren Builder-Modell und der
 * Definition-JSON, plus Kompilieren/Parsen einfacher Bedingungen. Keine Abhängigkeit
 * zu Angular oder HTTP — dadurch isoliert testbar.
 */

export type ConditionOp = '==' | '!=' | '>' | '<' | '>=' | '<=';

export interface BuilderCondition {
  field: string;
  op: ConditionOp;
  value: string;
}

export interface BuilderTransition {
  to: string;
  event: string | null;
  mode: 'assistant' | 'raw';
  condition: BuilderCondition;
  raw: string;
}

export interface BuilderField {
  name: string;
  label: string;
  type: string;
}

export interface BuilderStep {
  name: string;
  type: StepType;
  action: string | null;
  config: Record<string, unknown>;
  title: string;
  description: string;
  fields: BuilderField[];
  /** Referenz auf eine 'page'-Vorlage (nur interaktive Schritte), ui.templateId. */
  pageTemplateId: string;
  delaySeconds: number | null;
  transitions: BuilderTransition[];
}

export interface BuilderModel {
  id: string;
  name: string;
  startStep: string;
  steps: BuilderStep[];
}

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
}

function asArray(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}

function asString(value: unknown, fallback = ''): string {
  return typeof value === 'string' ? value : fallback;
}

function emptyCondition(): BuilderCondition {
  return { field: '', op: '==', value: '' };
}

function toLiteral(value: string): string {
  const v = value.trim();
  const low = v.toLowerCase();
  if (low === 'true' || low === 'ja' || low === 'wahr') {
    return 'true';
  }
  if (low === 'false' || low === 'nein' || low === 'falsch') {
    return 'false';
  }
  if (/^-?\d+(\.\d+)?$/.test(v)) {
    return v;
  }
  return `'${v.replace(/'/g, "\\'")}'`;
}

/** Baut einen when-Ausdruck aus Feld/Operator/Wert. Leeres Feld => 'true'. */
export function compileCondition(cond: BuilderCondition): string {
  if (cond.field.trim() === '') {
    return 'true';
  }
  return `context['${cond.field}'] ${cond.op} ${toLiteral(cond.value)}`;
}

/** Liest einen einfachen when-Ausdruck zurück; null, wenn er nicht ins Muster passt. */
export function parseCondition(when: string): BuilderCondition | null {
  const match = /^context\['([^']+)'\]\s*(==|!=|>=|<=|>|<)\s*(.+)$/.exec(when.trim());
  if (!match) {
    return null;
  }
  const field = match[1];
  const op = match[2] as ConditionOp;
  const raw = match[3].trim();
  const quoted = /^'(.*)'$/.exec(raw);
  const value = quoted ? quoted[1].replace(/\\'/g, "'") : raw;
  return { field, op, value };
}

function transitionFromJson(entry: Record<string, unknown>): BuilderTransition {
  const when = asString(entry['when'], 'true') || 'true';
  const parsed = parseCondition(when);
  const eventValue = entry['event'];
  return {
    to: asString(entry['to']),
    event: typeof eventValue === 'string' ? eventValue : null,
    mode: parsed ? 'assistant' : 'raw',
    condition: parsed ?? emptyCondition(),
    raw: when,
  };
}

function stepFromJson(name: string, raw: Record<string, unknown>): BuilderStep {
  const type = asString(raw['type'], 'automatic') as StepType;
  const ui = asRecord(raw['ui']);
  const delay = raw['delaySeconds'];
  const action = raw['action'];

  return {
    name,
    type,
    action: typeof action === 'string' ? action : null,
    config: asRecord(raw['config']),
    title: asString(ui['title']),
    description: asString(ui['description']),
    fields: asArray(ui['fields']).map((f) => {
      const field = asRecord(f);
      return {
        name: asString(field['name']),
        label: asString(field['label'], asString(field['name'])),
        type: asString(field['type'], 'text'),
      };
    }),
    pageTemplateId: asString(ui['templateId']),
    delaySeconds: typeof delay === 'number' ? delay : null,
    transitions: asArray(raw['transitions']).map((t) => transitionFromJson(asRecord(t))),
  };
}

/** Definition-JSON -> editierbares Builder-Modell. */
export function fromDefinition(json: Record<string, unknown>): BuilderModel {
  const id = asString(json['id']);
  return {
    id,
    name: asString(json['name'], id),
    startStep: asString(json['startStep']),
    steps: Object.entries(asRecord(json['steps'])).map(([name, s]) => stepFromJson(name, asRecord(s))),
  };
}

function transitionToJson(t: BuilderTransition): Record<string, unknown> {
  const when = t.mode === 'raw' ? t.raw.trim() || 'true' : compileCondition(t.condition);
  const out: Record<string, unknown> = { to: t.to };
  if (t.event !== null && t.event !== '') {
    out['event'] = t.event;
  }
  if (when !== 'true') {
    out['when'] = when;
  }
  return out;
}

function stepToJson(step: BuilderStep): Record<string, unknown> {
  const out: Record<string, unknown> = { type: step.type };

  if (step.type === 'automatic' && step.action) {
    out['action'] = step.action;
    if (Object.keys(step.config).length > 0) {
      out['config'] = step.config;
    }
  }

  if (step.type === 'interactive') {
    const events = Array.from(
      new Set(step.transitions.map((t) => t.event).filter((e): e is string => !!e)),
    );
    const ui: Record<string, unknown> = {
      title: step.title,
      description: step.description,
      fields: step.fields,
      events,
    };
    if (step.pageTemplateId) {
      ui['templateId'] = step.pageTemplateId;
    }
    out['ui'] = ui;
  }

  if (step.type === 'timer' && step.delaySeconds !== null) {
    out['delaySeconds'] = step.delaySeconds;
  }

  out['transitions'] = step.transitions.map(transitionToJson);
  return out;
}

/** Builder-Modell -> Definition-JSON (identisch zu dem, was die API erwartet). */
export function toDefinition(model: BuilderModel): Record<string, unknown> {
  const steps: Record<string, unknown> = {};
  for (const step of model.steps) {
    steps[step.name] = stepToJson(step);
  }
  return {
    id: model.id,
    name: model.name,
    startStep: model.startStep,
    steps,
  };
}

/** Reihenfolge der Schritte ab dem Start-Step (BFS) für die Ablauf-Vorschau. */
export function orderedStepNames(model: BuilderModel): string[] {
  const known = new Set(model.steps.map((s) => s.name));
  const byName = new Map(model.steps.map((s) => [s.name, s]));
  const visited: string[] = [];
  const seen = new Set<string>();
  const queue: string[] = model.startStep && known.has(model.startStep) ? [model.startStep] : [];

  while (queue.length > 0) {
    const name = queue.shift() as string;
    if (seen.has(name)) {
      continue;
    }
    seen.add(name);
    visited.push(name);
    for (const t of byName.get(name)?.transitions ?? []) {
      if (known.has(t.to) && !seen.has(t.to)) {
        queue.push(t.to);
      }
    }
  }

  // Nicht erreichbare Schritte hinten anhängen (damit sie sichtbar bleiben).
  for (const step of model.steps) {
    if (!seen.has(step.name)) {
      visited.push(step.name);
    }
  }
  return visited;
}

export function emptyStep(name: string, type: StepType): BuilderStep {
  return {
    name,
    type,
    action: null,
    config: {},
    title: type === 'interactive' ? name : '',
    description: '',
    fields: [],
    pageTemplateId: '',
    delaySeconds: type === 'timer' ? 3600 : null,
    transitions: [],
  };
}

export function emptyModel(): BuilderModel {
  return { id: '', name: '', startStep: '', steps: [] };
}
