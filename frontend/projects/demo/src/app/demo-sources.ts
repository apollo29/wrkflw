import { HttpErrorResponse, HttpEvent, HttpRequest, HttpResponse } from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { delay } from 'rxjs/operators';

/**
 * Ersatz für `/data-sources/*` (9.16): Verbindungen, REST-Datenmodelle, Trigger und
 * Protokoll mit denselben Prüfungen wie die Engine (vereinfacht). Zugangsdaten werden nie
 * gespeichert, nur ob welche hinterlegt sind.
 */

export interface DemoConnection {
  id: string;
  name: string;
  baseUrl: string;
  authType: 'none' | 'bearer' | 'api_key' | 'basic';
  authHeader: string | null;
  secretStored: boolean;
}

export interface DemoModel {
  name: string;
  label: string;
  connection: string;
  idField: string;
  listPath: string;
  subset: Record<string, string>;
  operations: Record<string, { method: string; path: string }>;
  fields: { name: string; label: string; type: string; path: string; writable: boolean }[];
}

export interface DemoTrigger {
  id: string;
  name: string;
  model: string;
  conditions: { field: string; operator: string; value: unknown }[];
  definitionId: string;
  inputs: Record<string, string>;
  intervalMinutes: number;
  lockDays: number;
  active: boolean;
  lastPolledAt: string | null;
}

export interface DemoAudit {
  at: string;
  actor: { id: string; name: string };
  action: string;
  objectType: string;
  objectId: string;
  detail: Record<string, unknown>;
}

export interface SourcesDb {
  connections: DemoConnection[];
  models: DemoModel[];
  triggers: DemoTrigger[];
  audit: DemoAudit[];
}

/** Was die Ablage über die Abläufe wissen muss (Verwendung, Eingaben, Status). */
export interface DefinitionView {
  id: string;
  version: number;
  status: string;
  definition: Record<string, unknown>;
}

const ID = /^[a-z0-9][a-z0-9_-]{0,63}$/;
const PATH = /^[A-Za-z_]\w*(\.\w+)*$/;

export function seedSources(): SourcesDb {
  const at = (days: number) => new Date(Date.now() - days * 86_400_000).toISOString();
  const actor = { id: 'demo', name: 'Demo' };
  return {
    connections: [
      {
        id: 'antrag-api',
        name: 'Antrags-API',
        baseUrl: 'https://antraege.example.org/api',
        authType: 'bearer',
        authHeader: null,
        secretStored: true,
      },
    ],
    models: [
      {
        name: 'antrag',
        label: 'Anträge',
        connection: 'antrag-api',
        idField: 'id',
        listPath: 'data.items',
        subset: { archiviert: 'false' },
        operations: {
          load: { method: 'GET', path: '/antraege/{id}' },
          find: { method: 'GET', path: '/antraege' },
          update: { method: 'PATCH', path: '/antraege/{id}' },
        },
        fields: [
          { name: 'id', label: 'ID', type: 'text', path: 'data.id', writable: false },
          { name: 'status', label: 'Status', type: 'text', path: 'data.status', writable: true },
          { name: 'betrag', label: 'Betrag', type: 'number', path: 'data.betrag', writable: false },
          {
            name: 'mail',
            label: 'E-Mail',
            type: 'text',
            path: 'data.kontakt.mail',
            writable: false,
          },
          {
            name: 'geprueft_am',
            label: 'Geprüft am',
            type: 'date',
            path: 'data.geprueft_am',
            writable: true,
          },
        ],
      },
    ],
    triggers: [
      {
        id: 'offene-antraege',
        name: 'Offene Anträge prüfen',
        model: 'antrag',
        conditions: [
          { field: 'status', operator: '=', value: 'offen' },
          { field: 'geprueft_am', operator: 'empty', value: null },
        ],
        definitionId: 'antrag-pruefung',
        inputs: { antrag_id: 'id' },
        intervalMinutes: 15,
        lockDays: 7,
        active: false,
        lastPolledAt: null,
      },
    ],
    audit: [
      {
        at: at(12),
        actor,
        action: 'connection.created',
        objectType: 'connection',
        objectId: 'antrag-api',
        detail: { changed: ['name', 'baseUrl', 'authType'], secretReplaced: true },
      },
      {
        at: at(11),
        actor,
        action: 'model.created',
        objectType: 'model',
        objectId: 'antrag',
        detail: {},
      },
      {
        at: at(10),
        actor,
        action: 'trigger.created',
        objectType: 'trigger',
        objectId: 'offene-antraege',
        detail: {},
      },
      {
        at: at(2),
        actor,
        action: 'connection.updated',
        objectType: 'connection',
        objectId: 'antrag-api',
        detail: { changed: ['baseUrl'], secretReplaced: false },
      },
    ],
  };
}

/** Beispielantwort für «Beispiel abrufen» (wie im Board). */
function sampleTree(id: string): Record<string, unknown> {
  return {
    data: {
      id,
      status: 'offen',
      betrag: 1250,
      kontakt: { mail: 'person@example.org' },
      archiviert: false,
      geprueft_am: null,
    },
  };
}

function samplePaths(
  node: unknown,
  prefix = '',
): { path: string; type: string; sample: unknown }[] {
  if (node === null || typeof node !== 'object' || Array.isArray(node)) {
    if (prefix === '') return [];
    const type =
      typeof node === 'boolean'
        ? 'bool'
        : typeof node === 'number'
          ? 'number'
          : typeof node === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(node)
            ? 'date'
            : 'text';
    return [{ path: prefix, type, sample: node }];
  }
  return Object.entries(node as Record<string, unknown>).flatMap(([k, v]) =>
    samplePaths(v, prefix === '' ? k : `${prefix}.${k}`),
  );
}

const ok = (body: unknown, status = 200): Observable<HttpEvent<unknown>> =>
  of(new HttpResponse({ status, body })).pipe(delay(20));

const fail = (
  status: number,
  code: string,
  message: string,
  details?: unknown,
): Observable<never> =>
  throwError(
    () =>
      new HttpErrorResponse({
        status,
        error: { error: { code, message, ...(details ? { details } : {}) } },
      }),
  );

const fields = (errors: Record<string, string[]>): Observable<never> =>
  fail(422, 'validation_error', 'Bitte die markierten Angaben prüfen.', { fields: errors });

function body(req: HttpRequest<unknown>): Record<string, unknown> {
  return (req.body ?? {}) as Record<string, unknown>;
}

const str = (v: unknown): string => (typeof v === 'string' ? v.trim() : '');

/** Schritte, die ein Datenmodell verwenden: `config.entity` mit Feldern. */
function modelUsage(defs: readonly DefinitionView[], name: string) {
  const usage: { definitionId: string; version: number; step: string; fields: string[] }[] = [];
  for (const d of defs) {
    const steps = (d.definition['steps'] ?? {}) as Record<string, Record<string, unknown>>;
    for (const [step, s] of Object.entries(steps)) {
      const config = (s['config'] ?? {}) as Record<string, unknown>;
      if (config['entity'] !== name) continue;
      const used = [
        config['field'],
        ...(Array.isArray(config['fields']) ? config['fields'] : []),
        ...Object.keys((config['values'] ?? {}) as object),
      ].filter((f): f is string => typeof f === 'string' && f !== '');
      usage.push({ definitionId: d.id, version: d.version, step, fields: [...new Set(used)] });
    }
  }
  return usage;
}

function log(
  db: SourcesDb,
  action: string,
  objectType: string,
  objectId: string,
  detail: Record<string, unknown> = {},
): void {
  db.audit.push({
    at: new Date().toISOString(),
    actor: { id: 'demo', name: 'Demo' },
    action,
    objectType,
    objectId,
    detail,
  });
}

function changed(before: object, after: object): string[] {
  const a = before as Record<string, unknown>;
  const b = after as Record<string, unknown>;
  return Object.keys(b).filter(
    (k) => k !== 'secretStored' && JSON.stringify(a[k]) !== JSON.stringify(b[k]),
  );
}

function connectionFrom(
  id: string,
  b: Record<string, unknown>,
  before: DemoConnection | undefined,
): DemoConnection | Record<string, string[]> {
  const errors: Record<string, string[]> = {};
  const authType = (
    ['none', 'bearer', 'api_key', 'basic'].includes(str(b['authType']))
      ? str(b['authType'])
      : 'none'
  ) as DemoConnection['authType'];
  if (!ID.test(id))
    errors['id'] = ['Die ID besteht aus Kleinbuchstaben, Ziffern, - und _ (höchstens 64 Zeichen).'];
  if (str(b['name']) === '') errors['name'] = ['Bitte einen Namen angeben.'];
  const url = str(b['baseUrl']);
  if (!/^https:\/\//i.test(url)) errors['baseUrl'] = ['Nur HTTPS-Adressen sind erlaubt.'];
  else if (/\/\/(localhost|127\.|10\.|192\.168\.)/i.test(url))
    errors['baseUrl'] = ['Die Adresse zeigt auf ein internes Netz und ist gesperrt.'];
  if (authType === 'api_key' && str(b['authHeader']) === '')
    errors['authHeader'] = ['Bitte die Kopfzeile für den API-Schlüssel angeben.'];
  if (Object.keys(errors).length > 0) return errors;
  const secret = str(b['secret']);
  return {
    id,
    name: str(b['name']),
    baseUrl: url.replace(/\/+$/, ''),
    authType,
    authHeader: authType === 'api_key' ? str(b['authHeader']) : null,
    secretStored: authType !== 'none' && (secret !== '' || (before?.secretStored ?? false)),
  };
}

function modelFrom(
  b: Record<string, unknown>,
  name: string,
  db: SourcesDb,
  defs: readonly DefinitionView[],
): DemoModel | Observable<never> {
  const label = str(b['label']);
  const connection = str(b['connection']);
  if (name === '' || label === '' || connection === '') {
    return fail(422, 'validation_error', 'Name, Bezeichnung und Verbindung sind Pflicht.');
  }
  if (!db.connections.some((c) => c.id === connection)) {
    return fail(404, 'not_found', `Verbindung '${connection}' nicht gefunden.`);
  }
  const ops = (b['operations'] ?? {}) as Record<string, { method?: string; path?: string }>;
  const operations: DemoModel['operations'] = {};
  for (const [kind, op] of Object.entries(ops)) {
    const path = str(op?.path);
    if (path === '') continue;
    if ((kind === 'load' || kind === 'update') && !path.includes('{id}')) {
      return fail(422, 'validation_error', `Der Pfad für '${kind}' braucht den Platzhalter {id}.`);
    }
    operations[kind] = { method: str(op?.method) || 'GET', path };
  }
  const raw = Array.isArray(b['fields']) ? (b['fields'] as Record<string, unknown>[]) : [];
  if (raw.length === 0)
    return fail(422, 'validation_error', `Datenmodell '${name}' hat keine Felder.`);
  const list: DemoModel['fields'] = raw.map((f) => ({
    name: str(f['name']),
    label: str(f['label']) || str(f['name']),
    type: str(f['type']) || 'text',
    path: str(f['path']) || str(f['name']),
    writable: f['writable'] === true,
  }));
  for (const f of list) {
    if (!PATH.test(f.path))
      return fail(
        422,
        'validation_error',
        `Feld '${f.name}': der Pfad '${f.path}' ist ungültig (z. B. kontakt.mail).`,
      );
  }
  const errors: Record<string, string[]> = {};
  const names = new Set(list.map((f) => f.name));
  for (const use of modelUsage(defs, name)) {
    for (const field of use.fields) {
      if (!names.has(field)) {
        errors[`fields.${field}`] = [
          `Das Feld wird noch verwendet in ${use.definitionId} v${use.version} (${use.step}).`,
        ];
      }
    }
  }
  const idField = str(b['idField']) || 'id';
  if (!names.has(idField)) errors['idField'] = [`Das ID-Feld '${idField}' ist nicht beschrieben.`];
  if (Object.keys(errors).length > 0) return fields(errors);
  const subset = Object.fromEntries(
    Object.entries((b['subset'] ?? {}) as Record<string, unknown>).map(([k, v]) => [k, String(v)]),
  );
  return {
    name,
    label,
    connection,
    idField,
    listPath: str(b['listPath']),
    subset,
    operations,
    fields: list,
  };
}

function triggerFrom(
  b: Record<string, unknown>,
  id: string,
  db: SourcesDb,
  defs: readonly DefinitionView[],
): DemoTrigger | Observable<never> {
  const errors: Record<string, string[]> = {};
  if (!ID.test(id)) errors['id'] = ['Die ID besteht aus Kleinbuchstaben, Ziffern, - und _.'];
  const model = db.models.find((m) => m.name === str(b['model']));
  if (!model) errors['model'] = [`Datenmodell '${str(b['model'])}' nicht gefunden.`];
  else if (!model.operations['find'])
    errors['model'] = ['Das Datenmodell erlaubt den Vorgang «Suchen» nicht.'];
  const latest = defs.filter((d) => d.id === str(b['definitionId'])).at(-1);
  if (!latest) errors['definitionId'] = [`Ablauf '${str(b['definitionId'])}' nicht gefunden.`];
  const conditions = (
    Array.isArray(b['conditions']) ? b['conditions'] : []
  ) as DemoTrigger['conditions'];
  const inputs = (b['inputs'] ?? {}) as Record<string, string>;
  if (model) {
    for (const c of conditions) {
      if (!model.fields.some((f) => f.name === c.field))
        (errors['conditions'] ??= []).push(`Das Feld '${c.field}' gibt es im Datenmodell nicht.`);
    }
  }
  const declared = (latest?.definition['inputs'] ?? []) as {
    name: string;
    label?: string;
    required?: boolean;
  }[];
  for (const input of declared) {
    if (input.required && !inputs[input.name])
      errors[`inputs.${input.name}`] = [
        `Die Pflichtangabe «${input.label ?? input.name}» braucht ein Feld.`,
      ];
  }
  const interval = typeof b['intervalMinutes'] === 'number' ? b['intervalMinutes'] : 15;
  const lockDays = typeof b['lockDays'] === 'number' ? b['lockDays'] : 7;
  if (interval < 5) errors['intervalMinutes'] = ['Geprüft wird höchstens alle 5 Minuten.'];
  if (lockDays < 1) errors['lockDays'] = ['Die Sperrfrist beträgt mindestens einen Tag.'];
  if (Object.keys(errors).length > 0) return fields(errors);
  return {
    id,
    name: str(b['name']) || id,
    model: model!.name,
    conditions,
    definitionId: latest!.id,
    inputs,
    intervalMinutes: interval,
    lockDays,
    active: b['active'] === true,
    lastPolledAt: db.triggers.find((t) => t.id === id)?.lastPolledAt ?? null,
  };
}

/** `/data-sources/…` (path ohne `data-sources`). `save` speichert die Ablage. */
export function dataSources(
  db: SourcesDb,
  defs: readonly DefinitionView[],
  url: URL,
  req: HttpRequest<unknown>,
  path: string[],
  save: () => void,
): Observable<HttpEvent<unknown>> {
  const [area, id, sub] = path;
  const m = req.method;
  const b = body(req);

  if (area === 'audit' && m === 'GET') {
    const type = url.searchParams.get('type');
    const object = url.searchParams.get('id');
    const limit = Number(url.searchParams.get('limit') ?? 100);
    const entries = db.audit
      .filter((e) => (!type || e.objectType === type) && (!object || e.objectId === object))
      .slice()
      .reverse()
      .slice(0, limit);
    return ok({ entries });
  }

  if (area === 'connections') {
    if (!id) {
      if (m === 'GET') return ok({ connections: db.connections });
      if (m === 'POST') {
        const cid = str(b['id']);
        if (db.connections.some((c) => c.id === cid))
          return fail(409, 'exists', `Eine Verbindung '${cid}' gibt es bereits.`);
        const c = connectionFrom(cid, b, undefined);
        if (!('baseUrl' in c && typeof c.baseUrl === 'string'))
          return fields(c as Record<string, string[]>);
        db.connections.push(c as DemoConnection);
        log(db, 'connection.created', 'connection', cid, {
          changed: ['name', 'baseUrl', 'authType'],
          secretReplaced: str(b['secret']) !== '',
        });
        save();
        return ok(c, 201);
      }
    }
    const found = db.connections.find((c) => c.id === id);
    if (!found) return fail(404, 'not_found', `Verbindung '${id}' nicht gefunden.`);
    const users = db.models.filter((x) => x.connection === id).map((x) => x.name);
    if (sub === 'usage' && m === 'GET') return ok({ id, models: users });
    if (sub === 'test' && m === 'POST') {
      return ok({
        ok: true,
        status: 200,
        durationMs: 140 + Math.round(Math.random() * 90),
        message: null,
      });
    }
    if (sub === 'secret' && m === 'PUT') {
      if (str(b['secret']) === '')
        return fields({ secret: ['Bitte die neuen Zugangsdaten angeben.'] });
      found.secretStored = true;
      log(db, 'connection.secret_replaced', 'connection', found.id);
      save();
      return ok(found);
    }
    if (!sub && m === 'GET') return ok(found);
    if (!sub && m === 'PUT') {
      const c = connectionFrom(found.id, b, found);
      if (!('baseUrl' in c && typeof c.baseUrl === 'string'))
        return fields(c as Record<string, string[]>);
      const next = c as DemoConnection;
      log(db, 'connection.updated', 'connection', found.id, {
        changed: changed(found, next),
        secretReplaced: str(b['secret']) !== '',
      });
      Object.assign(found, next);
      save();
      return ok(found);
    }
    if (!sub && m === 'DELETE') {
      if (users.length > 0)
        return fields({ connection: [`Die Verbindung wird verwendet von: ${users.join(', ')}.`] });
      db.connections = db.connections.filter((c) => c !== found);
      log(db, 'connection.deleted', 'connection', found.id);
      save();
      return ok(null, 204);
    }
  }

  if (area === 'models') {
    if (!id) {
      if (m === 'GET') return ok({ models: db.models });
      if (m === 'POST') {
        const name = str(b['name']);
        if (db.models.some((x) => x.name === name))
          return fail(409, 'exists', `Ein Datenmodell '${name}' gibt es bereits.`);
        const model = modelFrom(b, name, db, defs);
        if (model instanceof Observable) return model;
        db.models.push(model);
        log(db, 'model.created', 'model', name, {
          fields: model.fields.map((f) => f.name),
          operations: Object.keys(model.operations),
        });
        save();
        return ok(model, 201);
      }
    }
    if (id === 'sample' && m === 'POST') {
      const sid = str(b['id']);
      if (sid === '') return fields({ id: ['Bitte eine ID angeben.'] });
      const tree = sampleTree(sid);
      return ok({ status: 200, tree, paths: samplePaths(tree) });
    }
    const found = db.models.find((x) => x.name === id);
    if (!found) return fail(404, 'not_found', `Datenmodell '${id}' nicht gefunden.`);
    const usage = {
      name: found.name,
      definitions: modelUsage(defs, found.name),
      triggers: db.triggers.filter((t) => t.model === found.name).map((t) => t.id),
    };
    if (sub === 'usage' && m === 'GET') return ok(usage);
    if (!sub && m === 'GET') return ok(found);
    if (!sub && m === 'PUT') {
      const model = modelFrom(b, found.name, db, defs);
      if (model instanceof Observable) return model;
      Object.assign(found, model);
      log(db, 'model.updated', 'model', found.name, {
        fields: model.fields.map((f) => f.name),
        operations: Object.keys(model.operations),
      });
      save();
      return ok(found);
    }
    if (!sub && m === 'DELETE') {
      const reasons = [
        ...usage.definitions.map((u) => `${u.definitionId} v${u.version} (${u.step})`),
        ...usage.triggers.map((t) => `Trigger ${t}`),
      ];
      if (reasons.length > 0)
        return fields({
          model: [`Das Datenmodell wird noch verwendet in: ${reasons.join(', ')}.`],
        });
      db.models = db.models.filter((x) => x !== found);
      log(db, 'model.deleted', 'model', found.name);
      save();
      return ok(null, 204);
    }
  }

  if (area === 'triggers') {
    if (!id) {
      if (m === 'GET') return ok({ triggers: db.triggers });
      if (m === 'POST') {
        const tid = str(b['id']);
        if (db.triggers.some((t) => t.id === tid))
          return fail(409, 'exists', `Einen Trigger '${tid}' gibt es bereits.`);
        const trigger = triggerFrom(b, tid, db, defs);
        if (trigger instanceof Observable) return trigger;
        db.triggers.push(trigger);
        log(db, 'trigger.created', 'trigger', tid);
        save();
        return ok(trigger, 201);
      }
    }
    const found = db.triggers.find((t) => t.id === id);
    if (!found) return fail(404, 'not_found', `Trigger '${id}' nicht gefunden.`);
    // «Jetzt prüfen»: drei offene Demo-Anträge, gestartet wird nichts.
    if (sub === 'check-now' && m === 'POST')
      return ok({ id: found.id, matches: found.conditions.length > 0 ? 3 : 12 });
    if (!sub && m === 'GET') return ok(found);
    if (!sub && m === 'PUT') {
      const trigger = triggerFrom(b, found.id, db, defs);
      if (trigger instanceof Observable) return trigger;
      log(db, 'trigger.updated', 'trigger', found.id, { changed: changed(found, trigger) });
      Object.assign(found, trigger);
      save();
      return ok(found);
    }
    if (!sub && m === 'DELETE') {
      db.triggers = db.triggers.filter((t) => t !== found);
      log(db, 'trigger.deleted', 'trigger', found.id);
      save();
      return ok(null, 204);
    }
  }
  return fail(404, 'not_found', 'Diese Adresse gibt es in der Demo nicht.');
}
