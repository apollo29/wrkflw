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
  /**
   * Nur fuer `type: 'file'`: welche Pruefung die Host-App auf die hochgeladene
   * Datei anwendet. Die Engine kennt die moeglichen Werte NICHT — sie reicht
   * den String unveraendert durch, genau wie `ui` insgesamt. Welche Handler es
   * gibt, weiss allein die Host-App (in coach-admin z. B. `uefa_certificate`).
   */
  handler?: string;
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
  /**
   * `ui.public`: erscheint der Schritt auf der öffentlichen Seite?
   *
   * `null` = die Vorgabe der Host-App gilt (dort: nur Eingabe-Schritte). Sie
   * wird bewusst NICHT in die Definition geschrieben — sonst wäre jede
   * bestehende Definition beim nächsten Speichern um ein Feld reicher, das
   * nichts ändert.
   */
  publicVisible: boolean | null;
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
      const type = asString(field['type'], 'text');
      const out: BuilderField = {
        name: asString(field['name']),
        label: asString(field['label'], asString(field['name'])),
        type,
      };
      // `handler` nur bei Datei-Feldern uebernehmen: an einem Textfeld haette
      // er keine Wirkung, wuerde aber beim Speichern mitgeschrieben und beim
      // naechsten Laden wieder auftauchen.
      const handler = asString(field['handler']);
      if (type === 'file' && handler !== '') {
        out.handler = handler;
      }
      return out;
    }),
    pageTemplateId: asString(ui['templateId']),
    publicVisible: typeof ui['public'] === 'boolean' ? ui['public'] : null,
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

/**
 * `handler` gehoert an Datei-Felder und nur dorthin. Wer den Typ im Editor
 * nachtraeglich auf Text stellt, soll den Handler nicht als stille Altlast in
 * der Definition zuruecklassen.
 */
function fieldToJson(field: BuilderField): Record<string, unknown> {
  const out: Record<string, unknown> = {
    name: field.name,
    label: field.label,
    type: field.type,
  };
  if (field.type === 'file' && (field.handler ?? '') !== '') {
    out['handler'] = field.handler;
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
      fields: step.fields.map(fieldToJson),
      events,
    };
    if (step.pageTemplateId) {
      ui['templateId'] = step.pageTemplateId;
    }
    if (step.publicVisible !== null) {
      ui['public'] = step.publicVisible;
    }
    out['ui'] = ui;
  } else if (step.publicVisible !== null) {
    // Ein automatischer oder Timer-Schritt hat sonst gar kein `ui`. Wer ihn
    // sichtbar schaltet («Deine Anmeldung wird geprüft»), braucht trotzdem
    // eines — sonst wäre die Einstellung nach dem Speichern wieder weg.
    out['ui'] = { public: step.publicVisible };
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

/**
 * Einen Schritt entfernen und die Kette wieder schliessen.
 *
 * Nur das Element aus der Liste zu nehmen genügt nicht: die Übergänge zeigen
 * weiter auf seinen Namen. Die Ablaufreihenfolge bricht dort ab, alles
 * dahinter gilt als unerreichbar und rutscht im Editor ans Ende — was wie eine
 * zufällige Sortierung aussieht. Speichern liesse sich eine solche Definition
 * ausserdem nicht: ein Übergang auf einen Schritt, den es nicht gibt, ist ein
 * Fehler.
 *
 * Deshalb: hatte der gelöschte Schritt genau ein Ziel, erben die eingehenden
 * Übergänge dieses Ziel (A→B→C wird A→C). Gab es mehrere, wäre jede Wahl
 * geraten — dann fallen die eingehenden Übergänge weg und der Autor entscheidet
 * selbst.
 */
export function removeStep(model: BuilderModel, index: number): BuilderModel {
  const geloescht = model.steps[index];
  if (geloescht === undefined) {
    return model;
  }

  const uebrig = model.steps.filter((_, i) => i !== index);
  const vorhanden = new Set(uebrig.map((s) => s.name));
  const ersatz = bridgeTarget(geloescht, vorhanden);

  const steps = uebrig.map((step) => ({
    ...step,
    transitions: step.transitions
      .map((t) => (t.to === geloescht.name && ersatz !== null ? { ...t, to: ersatz } : t))
      // Verweise ins Leere fliegen raus — und eine Schleife auf sich selbst,
      // die erst durch die Brücke entstünde, ebenso: die hat niemand angelegt.
      .filter((t) => vorhanden.has(t.to) && t.to !== step.name),
  }));

  return {
    ...model,
    steps,
    startStep:
      model.startStep === geloescht.name
        ? ersatz ?? steps[0]?.name ?? ''
        : model.startStep,
  };
}

/** Das eindeutige Ziel des gelöschten Schritts, sonst null. */
function bridgeTarget(step: BuilderStep, vorhanden: Set<string>): string | null {
  const ziele = new Set(step.transitions.map((t) => t.to).filter((to) => vorhanden.has(to)));

  return ziele.size === 1 ? [...ziele][0] : null;
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
    publicVisible: null,
    delaySeconds: type === 'timer' ? 3600 : null,
    transitions: [],
  };
}

export function emptyModel(): BuilderModel {
  return { id: '', name: '', startStep: '', steps: [] };
}
