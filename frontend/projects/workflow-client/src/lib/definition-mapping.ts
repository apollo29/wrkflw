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
  /**
   * Beschriftung des Knopfes, der dieses Ereignis auslöst (`ui.eventLabels`).
   *
   * Ohne sie steht auf dem Knopf der rohe Ereignisname. Bei `submit` fällt das
   * nicht auf — die Anzeige kennt ein paar gängige Namen —, bei allem anderen
   * schon: ein zweiter Ausgang «ich komme nicht weiter» hiesse sonst «hilfe».
   */
  label: string;
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
   * `ui.back`: darf man von diesem Schritt aus einen Schritt zurück?
   *
   * Die Engine geht dabei zum letzten Eingabeschritt zurück und notfalls über
   * die Ablauf-Grenze hinweg in den Eltern-Ablauf. Standard ist `false` — ein
   * Zurück an einer Stelle, an der schon etwas verschickt oder abgelegt wurde,
   * wäre eine böse Überraschung. Deshalb wird es je Schritt freigegeben.
   */
  backAllowed: boolean;
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

/**
 * Ein Wert, den der Workflow beim Start erwartet (`inputs` in der Definition).
 *
 * Bisher stand nirgends, was eine Definition braucht — wer sie startet, musste
 * die Schritte lesen und die Platzhalter zusammensuchen. Fehlt etwas, lief der
 * Ablauf trotzdem an.
 */
export interface BuilderInput {
  name: string;
  label: string;
  required: boolean;
  /** Beispielwert, rein erklärend. */
  beispiel: string;
}

export interface BuilderModel {
  id: string;
  name: string;
  startStep: string;
  /** Leer = nicht deklariert; dann prüft die Engine beim Start nichts. */
  inputs: BuilderInput[];
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

function transitionFromJson(
  entry: Record<string, unknown>,
  labels: Record<string, unknown>,
): BuilderTransition {
  const when = asString(entry['when'], 'true') || 'true';
  const parsed = parseCondition(when);
  const eventValue = entry['event'];
  const event = typeof eventValue === 'string' ? eventValue : null;
  return {
    to: asString(entry['to']),
    event,
    mode: parsed ? 'assistant' : 'raw',
    condition: parsed ?? emptyCondition(),
    raw: when,
    // Die Beschriftungen liegen am Schritt (`ui.eventLabels`), nicht am
    // Uebergang: mehrere Uebergaenge koennen dasselbe Ereignis tragen, und
    // zwei Knoepfe mit demselben Namen und verschiedener Aufschrift waeren
    // fuer die Person davor nicht zu unterscheiden.
    label: event !== null ? asString(labels[event]) : '',
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
    backAllowed: ui['back'] === true,
    publicVisible: typeof ui['public'] === 'boolean' ? ui['public'] : null,
    delaySeconds: typeof delay === 'number' ? delay : null,
    transitions: asArray(raw['transitions']).map((t) =>
      transitionFromJson(asRecord(t), asRecord(ui['eventLabels'])),
    ),
  };
}

/** Definition-JSON -> editierbares Builder-Modell. */
export function fromDefinition(json: Record<string, unknown>): BuilderModel {
  const id = asString(json['id']);
  return {
    id,
    name: asString(json['name'], id),
    startStep: asString(json['startStep']),
    inputs: asArray(json['inputs']).map((i) => {
      const roh = asRecord(i);
      const name = asString(roh['name']);
      return {
        name,
        label: asString(roh['label'], name),
        required: roh['required'] === true,
        beispiel: asString(roh['beispiel']),
      };
    }),
    steps: Object.entries(asRecord(json['steps'])).map(([name, s]) => stepFromJson(name, asRecord(s))),
  };
}

/**
 * @param mitEreignis ob Ereignisse an diesem Schritt überhaupt etwas bewirken —
 *   nur beim interaktiven. Siehe die Erklärung in `stepToJson`.
 */
function transitionToJson(t: BuilderTransition, mitEreignis: boolean): Record<string, unknown> {
  const when = t.mode === 'raw' ? t.raw.trim() || 'true' : compileCondition(t.condition);
  const out: Record<string, unknown> = { to: t.to };
  if (mitEreignis && t.event !== null && t.event !== '') {
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
    // Nur schreiben, wenn freigegeben: sonst waere jede bestehende Definition
    // beim naechsten Speichern um ein `back: false` reicher, das nichts aendert.
    if (step.backAllowed) {
      ui['back'] = true;
    }
    if (step.publicVisible !== null) {
      ui['public'] = step.publicVisible;
    }
    const labels = eventLabelsOf(step);
    if (Object.keys(labels).length > 0) {
      ui['eventLabels'] = labels;
    }
    out['ui'] = ui;
  } else if (step.publicVisible !== null) {
    // Ein automatischer oder Timer-Schritt hat sonst gar kein `ui`. Wer ihn
    // sichtbar schaltet («Deine Anmeldung wird geprüft»), braucht trotzdem
    // eines — sonst wäre die Einstellung nach dem Speichern wieder weg.
    const ui: Record<string, unknown> = { public: step.publicVisible };
    // Und eine Überschrift, sonst steht in der Checkliste der technische
    // Schrittname. Beschreibung und Seitenvorlage kommen dazu, weil auch ein
    // Hintergrundschritt stehen bleiben kann — der Schritt, der auf einen
    // verknüpften Ablauf wartet, ist genau so einer, und die Seite zeigt dann
    // seinen Text.
    if (step.title !== '') {
      ui['title'] = step.title;
    }
    if (step.description !== '') {
      ui['description'] = step.description;
    }
    if (step.pageTemplateId !== '') {
      ui['templateId'] = step.pageTemplateId;
    }
    out['ui'] = ui;
  }

  if (step.type === 'timer' && step.delaySeconds !== null) {
    out['delaySeconds'] = step.delaySeconds;
  }

  // Ereignisse gehören an interaktive Schritte und nur dorthin.
  //
  // GEMELDET: nach Abschluss eines Kind-Workflows wurde der nächste Schritt im
  // Eltern-Ablauf übersprungen. Der Übergang aus dem Workflow-Schritt trug ein
  // `"event": "submit"` — stehengeblieben davon, dass der Schritt vorher
  // interaktiv war. Ein automatischer Schritt bekommt nie einen Knopfdruck; die
  // Engine fand keinen Weg hinaus und hielt das für das Ende des Ablaufs.
  //
  // Der Editor zeigt das Feld bei automatischen Schritten gar nicht an — genau
  // deshalb muss das Speichern es entfernen: sonst schleppte die Definition eine
  // Einstellung mit, die niemand mehr sehen oder ändern kann. Ein Umstellen des
  // Schritt-Typs repariert die Definition damit beim nächsten Speichern von
  // selbst.
  out['transitions'] = step.transitions.map((t) => transitionToJson(t, step.type === 'interactive'));
  return out;
}

/** Builder-Modell -> Definition-JSON (identisch zu dem, was die API erwartet). */
export function toDefinition(model: BuilderModel): Record<string, unknown> {
  const steps: Record<string, unknown> = {};
  for (const step of model.steps) {
    steps[step.name] = stepToJson(step);
  }
  const out: Record<string, unknown> = {
    id: model.id,
    name: model.name,
    startStep: model.startStep,
    steps,
  };

  // Namenlose Zeilen fliegen raus — der Editor legt eine an, sobald jemand
  // «+ Angabe» drückt. Und ohne Deklaration wird der Schlüssel gar nicht
  // geschrieben: sonst wäre jede bestehende Definition beim nächsten Speichern
  // um ein leeres Feld reicher.
  const inputs = model.inputs
    .filter((i) => i.name.trim() !== '')
    .map((i) => ({
      name: i.name.trim(),
      label: i.label.trim() || i.name.trim(),
      required: i.required,
      beispiel: i.beispiel.trim(),
    }));
  if (inputs.length > 0) {
    out['inputs'] = inputs;
  }

  return out;
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

/**
 * Die Knopf-Beschriftungen des Schritts, aus seinen Übergängen eingesammelt.
 *
 * Der erste Übergang mit einer Beschriftung gewinnt: tragen zwei Übergänge
 * dasselbe Ereignis, gibt es trotzdem nur einen Knopf.
 */
function eventLabelsOf(step: BuilderStep): Record<string, string> {
  const out: Record<string, string> = {};
  for (const t of step.transitions) {
    const event = t.event ?? '';
    const label = t.label.trim();
    if (event !== '' && label !== '' && out[event] === undefined) {
      out[event] = label;
    }
  }
  return out;
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

/**
 * Eine Spur einer Verzweigung: die Bedingung, unter der sie genommen wird, und
 * die Schritte, die auf ihr liegen.
 */
export interface PreviewLane {
  /** Der kompilierte Ausdruck. Leer, wenn der Uebergang bedingungslos ist. */
  condition: string;
  /** Bedingungsloser Uebergang — im Bild die «Sonst»-Spur. */
  isElse: boolean;
  /** Schritte dieser Spur, OHNE den Schritt der Zusammenfuehrung. */
  steps: string[];
}

/**
 * Ein Abschnitt der Ablauf-Vorschau: entweder eine Reihe von Schritten
 * hintereinander, oder eine Verzweigung.
 */
export type PreviewNode =
  | { kind: 'step'; name: string }
  | {
      kind: 'fork';
      /** Der Schritt, der verzweigt. */
      from: string;
      lanes: PreviewLane[];
      /** Der Schritt, in dem alle Spuren wieder zusammenlaufen — null, wenn keiner. */
      merge: string | null;
    };

/**
 * Der Ablauf als Folge von Abschnitten, mit Verzweigungen als eigener Art.
 *
 * WARUM NICHT `orderedStepNames()`: das ist eine Breitensuche und liefert eine
 * flache Liste. Bei einem Ablauf mit zwei Ausgaengen standen danach beide Ziele
 * nebeneinander in der Reihe, mit einem Pfeil dazwischen — als liefe der eine
 * nach dem anderen. Genau das Gegenteil von dem, was passiert: es laeuft
 * entweder der eine oder der andere, und wovon das abhaengt, war nicht zu
 * sehen. Die Reihe war also nicht unvollstaendig, sondern falsch.
 *
 * Deshalb hier eine eigene Form. Ein Schritt mit zwei oder mehr Uebergaengen
 * wird zur Verzweigung; je Uebergang eine Spur mit ihrer Bedingung. Laufen die
 * Spuren wieder in einem Schritt zusammen, ist das die Zusammenfuehrung, und
 * danach geht es einspurig weiter.
 *
 * Was die Vorschau BEWUSST NICHT zeigt: eine Verzweigung INNERHALB einer Spur.
 * Eine Spur endet am naechsten Schritt, der selbst verzweigt; der steht noch
 * als Schritt da, seine eigenen Ausgaenge nicht. Ein Bild, das jede Ebene
 * aufklappt, wird bei drei Ebenen unlesbar — und die Ausgaenge eines Schritts
 * stehen vollstaendig in seinem Detail-Panel. Die Vorschau ist eine Vorschau.
 *
 * Zyklen enden an ihrem ersten Wiedersehen: ein Ablauf darf im Kreis laufen
 * (eine Erinnerung, die erneut wartet), die Vorschau nicht.
 */
export function previewFlow(model: BuilderModel): PreviewNode[] {
  const known = new Set(model.steps.map((s) => s.name));
  const byName = new Map(model.steps.map((s) => [s.name, s]));

  const ausgaenge = (name: string): BuilderTransition[] =>
    (byName.get(name)?.transitions ?? []).filter((t) => known.has(t.to));

  /** Die einspurige Kette ab `start` — endet, wo es nicht mehr eindeutig weitergeht. */
  const kette = (start: string): string[] => {
    const out: string[] = [];
    const gesehen = new Set<string>();
    let name: string | null = start;
    while (name !== null && known.has(name) && !gesehen.has(name)) {
      gesehen.add(name);
      out.push(name);
      const weiter = ausgaenge(name);
      if (weiter.length !== 1) {
        break;
      }
      name = weiter[0].to;
    }
    return out;
  };

  /** Der erste Schritt, der auf JEDER Spur liegt — null, wenn es keinen gibt. */
  const zusammenfuehrung = (ketten: string[][]): string | null => {
    if (ketten.length === 0 || ketten.some((k) => k.length === 0)) {
      return null;
    }
    for (const name of ketten[0]) {
      if (ketten.every((k) => k.includes(name))) {
        return name;
      }
    }
    return null;
  };

  const out: PreviewNode[] = [];
  const besucht = new Set<string>();
  let cur: string | null =
    model.startStep && known.has(model.startStep) ? model.startStep : null;

  while (cur !== null && !besucht.has(cur)) {
    besucht.add(cur);
    const weiter = ausgaenge(cur);

    if (weiter.length < 2) {
      out.push({ kind: 'step', name: cur });
      cur = weiter.length === 1 ? weiter[0].to : null;
      continue;
    }

    const ketten = weiter.map((t) => kette(t.to));
    const merge = zusammenfuehrung(ketten);
    const lanes: PreviewLane[] = weiter.map((t, i) => {
      const bis = merge === null ? ketten[i].length : ketten[i].indexOf(merge);
      return {
        condition: laneCondition(t),
        isElse: laneIsElse(t),
        steps: ketten[i].slice(0, bis),
      };
    });

    // Was auf einer Spur steht, ist gezeichnet und damit erledigt. Ohne diese
    // Zeile landeten die Spur-Schritte am Ende als «nicht erreichbar» noch
    // einmal in der Reihe — erreichbar sind sie ja gerade.
    for (const lane of lanes) {
      for (const name of lane.steps) {
        besucht.add(name);
      }
    }

    out.push({ kind: 'fork', from: cur, lanes, merge });

    if (merge === null) {
      cur = null;
      continue;
    }

    // Der Schritt der Zusammenfuehrung ist im Bild der Verzweigung schon
    // gezeichnet. Verzweigt er selbst, bleibt er trotzdem `cur`: dann wird er
    // die Quelle der naechsten Verzweigung und steht dort nochmal — so wie im
    // Entwurf jede Verzweigung ihre Quelle als Zusammenhang wiederholt.
    // Verzweigt er nicht, geht es direkt bei seinem Ziel weiter.
    if (ausgaenge(merge).length >= 2) {
      cur = merge;
      continue;
    }
    besucht.add(merge);
    cur = ausgaenge(merge)[0]?.to ?? null;
  }

  // Nicht erreichbare Schritte hinten anhaengen — dieselbe Zusage wie in
  // orderedStepNames: ein Schritt, den niemand ansteuert, verschwindet nicht
  // lautlos aus dem Bild.
  for (const step of model.steps) {
    if (!besucht.has(step.name)) {
      out.push({ kind: 'step', name: step.name });
    }
  }

  return out;
}

/**
 * Bedingungslos?
 *
 * Drei Schreibweisen bedeuten «immer», und alle drei kommen vor:
 *
 *   - Assistent ohne Feld — so legt der Editor einen «Sonst»-Uebergang an.
 *   - `raw` ohne Text — ein leer gelassenes Ausdrucksfeld.
 *   - `raw` mit genau `true` — und DAS ist der Normalfall einer gespeicherten
 *     Definition. `transitionToJson()` schreibt fuer «immer» den Ausdruck
 *     `true`, und `parseCondition('true')` passt auf kein Muster, also kommt
 *     der Uebergang als `mode: 'raw'`, `raw: 'true'` zurueck. Ohne diesen Fall
 *     stuende in der Vorschau jedes zweiten geladenen Ablaufs die «Bedingung»
 *     `true`, wo «Sonst» hingehoert.
 */
function laneIsElse(t: BuilderTransition): boolean {
  if (t.mode === 'raw') {
    const ausdruck = t.raw.trim();

    return ausdruck === '' || ausdruck === 'true';
  }

  return t.condition.field.trim() === '';
}

function laneCondition(t: BuilderTransition): string {
  if (laneIsElse(t)) {
    return '';
  }
  return t.mode === 'raw' ? t.raw.trim() : compileCondition(t.condition);
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
    backAllowed: false,
    publicVisible: null,
    delaySeconds: type === 'timer' ? 3600 : null,
    transitions: [],
  };
}

export function emptyModel(): BuilderModel {
  return { id: '', name: '', startStep: '', inputs: [], steps: [] };
}
