import type {
  AuthType,
  Connection,
  ConnectionInput,
  FieldType,
  RestModel,
  Trigger,
  TriggerCondition,
} from '../api/models';

/**
 * Formulare der Datenquellen (9.16) als reine Abbildung: Entwurf ↔ API und die Prüfungen,
 * die auch die Engine macht. Fehler sind Katalog-Schlüssel, die Oberfläche übersetzt sie.
 */

/** Wie `DataSourceAdmin::ID_PATTERN` (Verbindungen und Trigger). */
export const SOURCE_ID_PATTERN = /^[a-z0-9][a-z0-9_-]{0,63}$/;
/** Wie `DataModel::NAME_PATTERN`. */
export const MODEL_NAME_PATTERN = /^[A-Za-z_][A-Za-z0-9_-]*$/;
/** Wie `DataModel::FIELD_PATTERN`. */
export const FIELD_NAME_PATTERN = /^[A-Za-z_]\w*$/;
/** Wie `RestModelSpec::PATH_PATTERN`. */
export const FIELD_PATH_PATTERN = /^[A-Za-z_]\w*(\.\w+)*$/;
/** Wie `AdminTrigger::MIN_INTERVAL_MINUTES`. */
export const MIN_INTERVAL_MINUTES = 5;

export type FormErrors = Record<string, string>;

// ------------------------------------------------------------------ Verbindungen

export const AUTH_TYPES: readonly AuthType[] = ['none', 'bearer', 'api_key', 'basic'];

export interface ConnectionDraft {
  id: string;
  name: string;
  baseUrl: string;
  authType: AuthType;
  authHeader: string;
  /** Nur Eingabe; ein gespeichertes Geheimnis kommt nie zurück (F-135). */
  secret: string;
  user: string;
  password: string;
}

export function connectionDraft(c: Connection | null): ConnectionDraft {
  return {
    id: c?.id ?? '',
    name: c?.name ?? '',
    baseUrl: c?.baseUrl ?? 'https://',
    authType: c?.authType ?? 'none',
    authHeader: c?.authHeader ?? '',
    secret: '',
    user: '',
    password: '',
  };
}

/** Das eingegebene Geheimnis (Basic: `benutzer:passwort`), leer = keins. */
export function enteredSecret(d: ConnectionDraft): string {
  switch (d.authType) {
    case 'none':
      return '';
    case 'basic':
      return d.user === '' && d.password === '' ? '' : `${d.user}:${d.password}`;
    default:
      return d.secret;
  }
}

export function toConnectionInput(d: ConnectionDraft): ConnectionInput & { id: string } {
  const secret = enteredSecret(d);
  return {
    id: d.id.trim(),
    name: d.name.trim(),
    baseUrl: d.baseUrl.trim(),
    authType: d.authType,
    ...(d.authType === 'api_key' ? { authHeader: d.authHeader.trim() } : {}),
    ...(secret !== '' ? { secret } : {}),
  };
}

export function validateConnection(d: ConnectionDraft): FormErrors {
  const errors: FormErrors = {};
  if (!SOURCE_ID_PATTERN.test(d.id.trim())) {
    errors['id'] = 'sources.id.invalid';
  }
  if (d.name.trim() === '') {
    errors['name'] = 'sources.name.missing';
  }
  if (!/^https:\/\/[^/\s]+/i.test(d.baseUrl.trim())) {
    errors['baseUrl'] = 'sources.connection.https';
  }
  if (d.authType === 'api_key' && d.authHeader.trim() === '') {
    errors['authHeader'] = 'sources.connection.header';
  }
  return errors;
}

// ------------------------------------------------------------------ Datenmodelle

export type OperationKind = 'load' | 'find' | 'update' | 'create';
export const OPERATION_KINDS: readonly OperationKind[] = ['load', 'find', 'update', 'create'];
/** Erlaubte Methoden je Vorgang (wie `RestModelSpec::METHODS`), die erste ist Vorgabe. */
export const OPERATION_METHODS: Readonly<Record<OperationKind, readonly string[]>> = {
  load: ['GET'],
  find: ['GET', 'POST'],
  update: ['PATCH', 'PUT', 'POST'],
  create: ['POST', 'PUT'],
};
export const FIELD_TYPES: readonly FieldType[] = ['text', 'number', 'bool', 'date', 'datetime'];

export interface FieldDraft {
  name: string;
  label: string;
  type: FieldType;
  path: string;
  writable: boolean;
}

export interface ModelDraft {
  name: string;
  label: string;
  connection: string;
  idField: string;
  listPath: string;
  subset: { key: string; value: string }[];
  operations: Record<OperationKind, { method: string; path: string }>;
  fields: FieldDraft[];
}

export function modelDraft(m: RestModel | null): ModelDraft {
  const operations = {} as ModelDraft['operations'];
  for (const kind of OPERATION_KINDS) {
    const op = m?.operations[kind];
    operations[kind] = { method: op?.method ?? OPERATION_METHODS[kind][0]!, path: op?.path ?? '' };
  }
  return {
    name: m?.name ?? '',
    label: m?.label ?? '',
    connection: m?.connection ?? '',
    idField: m?.idField ?? 'id',
    listPath: m?.listPath ?? '',
    subset: Object.entries(m?.subset ?? {}).map(([key, value]) => ({ key, value })),
    operations,
    fields: (m?.fields ?? []).map((f) => ({
      name: f.name,
      label: f.label ?? f.name,
      type: f.type ?? 'text',
      path: f.path ?? f.name,
      writable: f.writable === true,
    })),
  };
}

/** «schreibbar» gibt es nur mit «Ändern» oder «Anlegen». */
export function canWrite(d: ModelDraft): boolean {
  return d.operations.update.path.trim() !== '' || d.operations.create.path.trim() !== '';
}

export function toRestModel(d: ModelDraft): RestModel {
  const operations: RestModel['operations'] = {};
  for (const kind of OPERATION_KINDS) {
    const op = d.operations[kind];
    if (op.path.trim() !== '') {
      operations[kind] = { method: op.method, path: op.path.trim() };
    }
  }
  const writable = canWrite(d);
  return {
    name: d.name.trim(),
    label: d.label.trim(),
    connection: d.connection,
    idField: d.idField,
    listPath: d.listPath.trim(),
    subset: Object.fromEntries(
      d.subset.filter((s) => s.key.trim() !== '').map((s) => [s.key.trim(), s.value]),
    ),
    operations,
    fields: d.fields.map((f) => ({
      name: f.name.trim(),
      label: f.label.trim() || f.name.trim(),
      type: f.type,
      path: f.path.trim() || f.name.trim(),
      writable: writable && f.writable,
    })),
  };
}

export function validateModel(d: ModelDraft): FormErrors {
  const errors: FormErrors = {};
  if (d.name.trim() === '') {
    errors['name'] = 'sources.name.missing';
  } else if (!MODEL_NAME_PATTERN.test(d.name.trim())) {
    errors['name'] = 'sources.model.name';
  }
  if (d.label.trim() === '') {
    errors['label'] = 'sources.name.missing';
  }
  if (d.connection === '') {
    errors['connection'] = 'sources.model.connection';
  }
  for (const kind of ['load', 'update'] as const) {
    const path = d.operations[kind].path.trim();
    if (path !== '' && !path.includes('{id}')) {
      errors[`operations.${kind}`] = 'sources.model.idPlaceholder';
    }
  }
  if (d.listPath.trim() !== '' && !FIELD_PATH_PATTERN.test(d.listPath.trim())) {
    errors['listPath'] = 'sources.model.path';
  }
  if (d.fields.length === 0) {
    errors['fields'] = 'sources.model.noFields';
    return errors;
  }
  const seen = new Set<string>();
  d.fields.forEach((f, i) => {
    const name = f.name.trim();
    const path = f.path.trim() || name;
    if (!FIELD_NAME_PATTERN.test(name)) {
      errors[`fields.${i}`] = 'sources.model.fieldName';
    } else if (seen.has(name)) {
      errors[`fields.${i}`] = 'sources.model.fieldDuplicate';
    } else if (!FIELD_PATH_PATTERN.test(path)) {
      errors[`fields.${i}`] = 'sources.model.path';
    }
    seen.add(name);
  });
  if (!seen.has(d.idField)) {
    errors['idField'] = 'sources.model.idField';
  }
  return errors;
}

/** Ein Wert aus «Beispiel abrufen» als neues Feld (Name = letzter Schlüssel, eindeutig). */
export function fieldFromSample(
  sample: { readonly path: string; readonly type: string; readonly sample?: unknown },
  existing: readonly string[],
): FieldDraft {
  const base = (sample.path.split('.').at(-1) ?? 'feld').replace(/^[^A-Za-z_]/, '_$&');
  let name = base;
  for (let n = 2; existing.includes(name); n++) {
    name = `${base}_${n}`;
  }
  const type = (FIELD_TYPES as readonly string[]).includes(sample.type)
    ? (sample.type as FieldType)
    : 'text';
  return { name, label: name, type, path: sample.path, writable: false };
}

export interface SampleNode {
  readonly depth: number;
  readonly key: string;
  readonly path: string;
  readonly kind: 'group' | 'leaf' | 'list';
  /** Blatt: Wert wie in JSON; Liste: Anzahl Einträge. */
  readonly value?: string;
}

/** Baum aus «Beispiel abrufen» als Zeilen. Listen lassen sich nicht als Feld übernehmen. */
export function sampleNodes(tree: unknown, prefix = '', depth = 0): SampleNode[] {
  if (tree === null || typeof tree !== 'object' || Array.isArray(tree)) {
    return [];
  }
  const rows: SampleNode[] = [];
  for (const [key, value] of Object.entries(tree as Record<string, unknown>)) {
    if (!/^[A-Za-z_]\w*$/.test(key)) {
      continue;
    }
    const path = prefix === '' ? key : `${prefix}.${key}`;
    if (Array.isArray(value)) {
      rows.push({ depth, key, path, kind: 'list', value: String(value.length) });
    } else if (value !== null && typeof value === 'object') {
      rows.push({ depth, key, path, kind: 'group' });
      rows.push(...sampleNodes(value, path, depth + 1));
    } else {
      rows.push({ depth, key, path, kind: 'leaf', value: JSON.stringify(value) });
    }
  }
  return rows;
}

// ------------------------------------------------------------------ Trigger

export const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'empty', 'not_empty'] as const;
export type Operator = (typeof OPERATORS)[number];
export const OPERATOR_KEYS: Readonly<Record<Operator, string>> = {
  '=': 'sources.op.eq',
  '!=': 'sources.op.ne',
  '<': 'sources.op.lt',
  '<=': 'sources.op.le',
  '>': 'sources.op.gt',
  '>=': 'sources.op.ge',
  empty: 'sources.op.empty',
  not_empty: 'sources.op.notEmpty',
};

export function needsValue(operator: string): boolean {
  return operator !== 'empty' && operator !== 'not_empty';
}

export interface ConditionDraft {
  field: string;
  operator: string;
  value: string;
}

export interface TriggerDraft {
  id: string;
  name: string;
  model: string;
  conditions: ConditionDraft[];
  definitionId: string;
  inputs: Record<string, string>;
  every: number;
  unit: 'min' | 'h';
  lockDays: number;
  active: boolean;
}

export function triggerDraft(t: Trigger | null): TriggerDraft {
  const minutes = t?.intervalMinutes ?? 15;
  const hours = minutes >= 60 && minutes % 60 === 0;
  return {
    id: t?.id ?? '',
    name: t?.name ?? '',
    model: t?.model ?? '',
    conditions: (t?.conditions ?? []).map((c) => ({
      field: c.field,
      operator: c.operator,
      value: c.value === null ? '' : String(c.value),
    })),
    definitionId: t?.definitionId ?? '',
    inputs: { ...(t?.inputs ?? {}) },
    every: hours ? minutes / 60 : minutes,
    unit: hours ? 'h' : 'min',
    lockDays: t?.lockDays ?? 7,
    // Ein neuer Trigger ist aus.
    active: t?.active ?? false,
  };
}

export function intervalMinutes(d: Pick<TriggerDraft, 'every' | 'unit'>): number {
  return Math.round(d.unit === 'h' ? d.every * 60 : d.every);
}

/** Wert nach Feldtyp: Zahl → number, Ja/Nein → boolean, sonst Text. */
export function conditionValue(
  c: ConditionDraft,
  type: FieldType | undefined,
): TriggerCondition['value'] {
  if (!needsValue(c.operator)) {
    return null;
  }
  if (type === 'number') {
    const n = Number(c.value.replace(',', '.'));
    return c.value.trim() === '' || Number.isNaN(n) ? null : n;
  }
  if (type === 'bool') {
    return c.value === 'true';
  }
  return c.value;
}

export function toTrigger(
  d: TriggerDraft,
  fields: readonly { readonly name: string; readonly type?: FieldType }[],
): Trigger {
  const types = new Map(fields.map((f) => [f.name, f.type]));
  return {
    id: d.id.trim(),
    name: d.name.trim() || d.id.trim(),
    model: d.model,
    conditions: d.conditions
      .filter((c) => c.field !== '')
      .map((c) => ({
        field: c.field,
        operator: c.operator,
        value: conditionValue(c, types.get(c.field)),
      })),
    definitionId: d.definitionId,
    inputs: Object.fromEntries(Object.entries(d.inputs).filter(([, field]) => field !== '')),
    intervalMinutes: intervalMinutes(d),
    lockDays: d.lockDays,
    active: d.active,
  };
}

export function validateTrigger(
  d: TriggerDraft,
  inputs: readonly { readonly name: string; readonly required: boolean }[],
): FormErrors {
  const errors: FormErrors = {};
  if (!SOURCE_ID_PATTERN.test(d.id.trim())) {
    errors['id'] = 'sources.id.invalid';
  }
  if (d.model === '') {
    errors['model'] = 'sources.trigger.model';
  }
  if (d.definitionId === '') {
    errors['definitionId'] = 'sources.trigger.workflow';
  }
  for (const input of inputs) {
    if (input.required && !d.inputs[input.name]) {
      errors[`inputs.${input.name}`] = 'sources.trigger.inputRequired';
    }
  }
  if (!(intervalMinutes(d) >= MIN_INTERVAL_MINUTES)) {
    errors['intervalMinutes'] = 'sources.trigger.interval';
  }
  if (!(d.lockDays >= 1)) {
    errors['lockDays'] = 'sources.trigger.lock';
  }
  return errors;
}
