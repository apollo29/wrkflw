import {
  HttpErrorResponse,
  HttpEvent,
  HttpInterceptorFn,
  HttpRequest,
  HttpResponse,
} from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { delay } from 'rxjs/operators';
import {
  DEMO_ACTIONS,
  DEMO_DEFINITIONS,
  DEMO_INSTANCES,
  DEMO_MODELS,
  DEMO_TEMPLATES,
  DEMO_UPLOAD_HANDLERS,
  DemoInstanceSeed,
} from './demo-data';

/*
 * Ersatz-Backend der Demo im Browser (bis Phase 12 das echte Backend anbindet). Bildet die
 * Routen nach, die der Client braucht; Zustand in localStorage.
 */

const KEY = 'wrkflw-demo-v2';

type Status = 'running' | 'waiting_event' | 'waiting_timer' | 'completed' | 'failed' | 'cancelled';
const RUNNING: readonly Status[] = ['running', 'waiting_event', 'waiting_timer'];

interface StoredVersion {
  version: number;
  status: 'active' | 'inactive' | 'draft';
  definition: Record<string, unknown>;
  createdAt: string;
  updatedAt: string;
}

interface HistoryRow {
  kind: string;
  step: string | null;
  detail: Record<string, unknown>;
  at: string;
}

interface StoredInstance {
  id: string;
  definitionId: string;
  definitionVersion: number;
  currentStep: string;
  status: Status;
  subjectType: string | null;
  subjectId: string | null;
  wakeAt: string | null;
  lastError: string | null;
  createdAt: string;
  updatedAt: string;
  history: HistoryRow[];
}

interface Db {
  definitions: Record<string, StoredVersion[]>;
  instances: StoredInstance[];
}

interface ValidationError {
  code: string;
  message: string;
  step: string | null;
  transition: number | null;
}

const now = (): string => new Date().toISOString();
const ago = (days: number, hours = 0): string =>
  new Date(Date.now() - days * 86_400_000 - hours * 3_600_000).toISOString();

function demoHistory(seed: DemoInstanceSeed, start: string): HistoryRow[] {
  const at = (minutes: number): string =>
    new Date(new Date(start).getTime() + minutes * 60_000).toISOString();
  const h: HistoryRow[] = [
    { kind: 'start', step: 'daten_laden', detail: { contextKeys: ['antrag_id'] }, at: at(0) },
    {
      kind: 'action',
      step: 'daten_laden',
      detail: { action: 'check_data', resultKeys: ['antrag', 'antragFound'] },
      at: at(0),
    },
    {
      kind: 'transition',
      step: 'daten_laden',
      detail: { to: seed.step === 'warten' ? 'warten' : 'pruefen' },
      at: at(0),
    },
  ];
  if (seed.step === 'warten') {
    h.push({ kind: 'wait_timer', step: 'warten', detail: {}, at: at(1) });
    return h;
  }
  h.push({ kind: 'wait_event', step: 'pruefen', detail: {}, at: at(1) });
  if (seed.status === 'waiting_event') {
    return h;
  }
  if (seed.status === 'cancelled') {
    h.push({ kind: 'cancelled', step: 'pruefen', detail: { reason: 'manual' }, at: at(90) });
    return h;
  }
  h.push({
    kind: 'event',
    step: 'pruefen',
    detail: { event: 'submit', payloadKeys: ['bemerkung'] },
    at: at(60),
  });
  h.push({
    kind: 'action',
    step: 'speichern',
    detail: { action: 'write_data', resultKeys: ['written', 'writtenCount'] },
    at: at(60),
  });
  h.push({ kind: 'transition', step: 'speichern', detail: { to: 'bestaetigung' }, at: at(60) });
  if (seed.status === 'failed') {
    h.push({
      kind: 'retry',
      step: 'bestaetigung',
      detail: { attempt: 1, maxAttempts: 3 },
      at: at(61),
    });
    h.push({
      kind: 'error',
      step: 'bestaetigung',
      detail: { message: seed.lastError ?? '' },
      at: at(75),
    });
    return h;
  }
  h.push({
    kind: 'action',
    step: 'bestaetigung',
    detail: { action: 'send_email', resultKeys: ['lastEmailTo'] },
    at: at(61),
  });
  h.push({ kind: 'transition', step: 'bestaetigung', detail: { to: 'abschluss' }, at: at(61) });
  h.push({ kind: 'complete', step: 'abschluss', detail: {}, at: at(62) });
  return h;
}

function seed(): Db {
  const definitions: Db['definitions'] = {};
  for (const raw of DEMO_DEFINITIONS) {
    const { draft, ...definition } = raw as Record<string, unknown> & { draft?: boolean };
    const id = definition['id'] as string;
    // antrag-pruefung hat drei Versionen wie in 9.13.
    const versions = id === 'antrag-pruefung' ? [1, 2, 3] : [1];
    definitions[id] = versions.map((version, i) => ({
      version,
      status: draft ? 'draft' : i < versions.length - 1 ? 'inactive' : 'active',
      definition,
      createdAt: ago(60 - version * 10),
      updatedAt: ago(i < versions.length - 1 ? 50 - version * 10 : 4),
    }));
  }
  const instances = DEMO_INSTANCES.map((s): StoredInstance => {
    const start = ago(s.daysAgo, 2);
    return {
      id: s.id,
      definitionId: 'antrag-pruefung',
      definitionVersion: s.version,
      currentStep: s.step,
      status: s.status,
      subjectType: 'antrag',
      subjectId: s.subject,
      wakeAt: s.wakeInHours ? new Date(Date.now() + s.wakeInHours * 3_600_000).toISOString() : null,
      lastError: s.lastError ?? null,
      createdAt: start,
      updatedAt: ago(s.daysAgo, 1),
      history: demoHistory(s, start),
    };
  });
  return { definitions, instances };
}

function load(): Db {
  try {
    const raw = localStorage.getItem(KEY);
    if (raw) {
      return JSON.parse(raw) as Db;
    }
  } catch {
    // ohne Speicher: Beispieldaten
  }
  const db = seed();
  persist(db);
  return db;
}

function persist(db: Db): void {
  try {
    localStorage.setItem(KEY, JSON.stringify(db));
  } catch {
    // nur im Speicher
  }
}

/** Grundprüfungen wie die Engine (vereinfachte Fassung). */
function validate(definition: Record<string, unknown>): ValidationError[] {
  const errors: ValidationError[] = [];
  const steps = (definition['steps'] ?? {}) as Record<string, Record<string, unknown>>;
  const names = Object.keys(steps);
  const start = definition['startStep'] as string | undefined;
  if (names.length === 0) {
    return [
      {
        code: 'no_steps',
        message: 'Der Workflow hat noch keine Schritte.',
        step: null,
        transition: null,
      },
    ];
  }
  if (!start || !steps[start]) {
    errors.push({
      code: 'start_step_missing',
      message: 'Der Start-Schritt fehlt.',
      step: null,
      transition: null,
    });
  }
  const reach = new Set<string>();
  const queue = start && steps[start] ? [start] : [];
  while (queue.length) {
    const name = queue.shift()!;
    if (reach.has(name)) continue;
    reach.add(name);
    for (const t of (steps[name]?.['transitions'] ?? []) as Record<string, unknown>[]) {
      if (steps[t['to'] as string]) queue.push(t['to'] as string);
    }
  }
  for (const name of names) {
    const step = steps[name]!;
    const transitions = (step['transitions'] ?? []) as Record<string, unknown>[];
    transitions.forEach((t, i) => {
      if (!steps[t['to'] as string]) {
        errors.push({
          code: 'target_unknown',
          message: `Übergang ${i + 1} zeigt auf «${String(t['to'])}», den es nicht gibt.`,
          step: name,
          transition: i,
        });
      }
    });
    if (!reach.has(name)) {
      errors.push({
        code: 'unreachable',
        message: 'Vom Start aus nicht erreichbar.',
        step: name,
        transition: null,
      });
    }
    const type = (step['type'] as string) ?? 'automatic';
    if (type === 'automatic' && !DEMO_ACTIONS.some((a) => a.key === step['action'])) {
      errors.push({
        code: 'rule_violation',
        message: 'Keine gültige Aktion gewählt.',
        step: name,
        transition: null,
      });
    }
    if (
      type === 'interactive' &&
      transitions.length > 0 &&
      !transitions.some((t) => typeof t['event'] === 'string')
    ) {
      errors.push({
        code: 'interactive_without_event',
        message: 'Mindestens ein Übergang braucht ein Ereignis (Knopf).',
        step: name,
        transition: null,
      });
    }
  }
  return errors;
}

function structure(definition: Record<string, unknown>): unknown {
  const steps = (definition['steps'] ?? {}) as Record<string, Record<string, unknown>>;
  const kind = (s: Record<string, unknown>): string => {
    if (s['type'] === 'interactive' || s['type'] === 'timer') return s['type'];
    return (
      (
        { start_workflow: 'workflow', check_data: 'check', write_data: 'write' } as Record<
          string,
          string
        >
      )[s['action'] as string] ?? 'automatic'
    );
  };
  return {
    startStep: definition['startStep'],
    steps: Object.entries(steps).map(([name, s]) => ({
      name,
      kind: kind(s),
      transitions: ((s['transitions'] ?? []) as Record<string, unknown>[]).map((t) => ({
        to: t['to'],
        conditional: typeof t['when'] === 'string' && t['when'] !== 'true',
        event: t['event'] ?? null,
      })),
    })),
  };
}

function ok(body: unknown, status = 200): Observable<HttpEvent<unknown>> {
  return of(new HttpResponse({ status, body })).pipe(delay(20));
}

function fail(status: number, code: string, message: string, details?: unknown): Observable<never> {
  return throwError(
    () =>
      new HttpErrorResponse({
        status,
        error: { error: { code, message, ...(details ? { details } : {}) } },
      }),
  );
}

function runs(db: Db, id: string, version?: number): { total: number; running: number } {
  const mine = db.instances.filter(
    (i) => i.definitionId === id && (version === undefined || i.definitionVersion === version),
  );
  return { total: mine.length, running: mine.filter((i) => RUNNING.includes(i.status)).length };
}

function versionView(id: string, list: StoredVersion[], v: StoredVersion) {
  return {
    id,
    version: v.version,
    status: v.status,
    readOnly: v !== list.at(-1),
    updatedAt: v.updatedAt,
    definition: v.definition,
  };
}

function instances(
  db: Db,
  url: URL,
  req: HttpRequest<unknown>,
  path: string[],
): Observable<HttpEvent<unknown>> {
  if (req.method === 'GET' && path.length === 1) {
    const status = url.searchParams.get('status');
    const definition = url.searchParams.get('definition');
    const limit = Number(url.searchParams.get('limit') ?? 50);
    const offset = Number(url.searchParams.get('offset') ?? 0);
    const scoped = db.instances.filter((i) => !definition || i.definitionId === definition);
    const counts: Record<Status, number> = {
      running: 0,
      waiting_event: 0,
      waiting_timer: 0,
      completed: 0,
      failed: 0,
      cancelled: 0,
    };
    scoped.forEach((i) => counts[i.status]++);
    const list = scoped
      .filter((i) => !status || i.status === status)
      .sort((a, b) => b.updatedAt.localeCompare(a.updatedAt))
      .slice(offset, offset + limit)
      .map(({ history: _history, ...rest }) => {
        const def = db.definitions[rest.definitionId]?.find(
          (v) => v.version === rest.definitionVersion,
        );
        return {
          ...rest,
          definitionName: (def?.definition['name'] as string) ?? rest.definitionId,
        };
      });
    return ok({ instances: list, counts });
  }
  const instance = db.instances.find((i) => i.id === path[1]);
  if (!instance) {
    return fail(404, 'not_found', `Durchlauf '${path[1] ?? ''}' nicht gefunden.`);
  }
  if (req.method === 'GET' && path[2] === 'history') {
    return ok({ instanceId: instance.id, history: instance.history });
  }
  if (req.method === 'POST' && path[2] === 'cancel') {
    if (!RUNNING.includes(instance.status)) {
      return fail(409, 'invalid_state', 'Der Durchlauf ist bereits beendet.');
    }
    instance.status = 'cancelled';
    instance.wakeAt = null;
    instance.updatedAt = now();
    instance.history.push({
      kind: 'cancelled',
      step: instance.currentStep,
      detail: { reason: 'manual' },
      at: now(),
    });
    persist(db);
    return ok({ id: instance.id, status: instance.status, currentStep: instance.currentStep });
  }
  return fail(404, 'not_found', 'Diese Adresse gibt es in der Demo nicht.');
}

function workflows(
  db: Db,
  req: HttpRequest<unknown>,
  path: string[],
): Observable<HttpEvent<unknown>> {
  const body = (req.body ?? {}) as Record<string, unknown>;
  const defs = db.definitions;

  if (req.method === 'POST' && path[1] === 'validate') {
    const errors = validate((body['definition'] ?? {}) as Record<string, unknown>);
    return ok({ valid: errors.length === 0, errors });
  }

  if (req.method === 'GET' && path.length === 1) {
    return ok({
      definitions: Object.entries(defs).map(([id, list]) => {
        const latest = list.at(-1)!;
        const counts = runs(db, id);
        return {
          id,
          name: (latest.definition['name'] as string) || id,
          version: latest.version,
          status: latest.status,
          updatedAt: latest.updatedAt,
          runningInstances: counts.running,
          totalInstances: counts.total,
          olderVersions: list.length - 1,
          olderRunning: list
            .slice(0, -1)
            .map((v) => ({ version: v.version, running: runs(db, id, v.version).running }))
            .filter((v) => v.running > 0)
            .reverse(),
          structure: structure(latest.definition),
        };
      }),
    });
  }

  const id = path[1] ?? '';
  const list = defs[id];

  if (req.method === 'POST' && path.length === 2) {
    const status = (body['status'] as StoredVersion['status']) ?? 'active';
    const definition: Record<string, unknown> = {
      ...((body['definition'] ?? {}) as Record<string, unknown>),
      id,
    };
    if (typeof body['name'] === 'string') definition['name'] = body['name'];
    const errors = validate(definition);
    if (status === 'active' && errors.length > 0) {
      return fail(422, 'invalid_definition', errors.map((e) => e.message).join('; '), { errors });
    }
    const versions = list ?? [];
    const latest = versions.at(-1);
    let saved: StoredVersion;
    if (latest && status !== 'active' && runs(db, id, latest.version).total === 0) {
      saved = { ...latest, status, definition, updatedAt: now() };
      versions[versions.length - 1] = saved;
    } else {
      saved = {
        version: (latest?.version ?? 0) + 1,
        status,
        definition,
        createdAt: now(),
        updatedAt: now(),
      };
      versions.push(saved);
    }
    defs[id] = versions;
    persist(db);
    return ok(
      {
        id,
        version: saved.version,
        status,
        newVersion: saved.version !== latest?.version,
        validation: errors,
      },
      201,
    );
  }

  if (!list) {
    return fail(404, 'not_found', `Workflow '${id}' nicht gefunden.`);
  }
  if (req.method === 'GET' && path.length === 2) {
    return ok(versionView(id, list, list.at(-1)!));
  }
  if (req.method === 'GET' && path[2] === 'versions' && path.length === 3) {
    return ok({
      id,
      versions: [...list].reverse().map((v, i) => {
        const counts = runs(db, id, v.version);
        return {
          version: v.version,
          name: (v.definition['name'] as string) || id,
          status: v.status,
          createdAt: v.createdAt,
          updatedAt: v.updatedAt,
          runningInstances: counts.running,
          totalInstances: counts.total,
          deletable: i > 0 && counts.total === 0,
          deleteBlockedBy: i === 0 ? 'latest' : counts.total > 0 ? 'referenced' : null,
        };
      }),
    });
  }
  if (path[2] === 'versions' && path[3]) {
    const v = list.find((x) => x.version === Number(path[3]));
    if (!v) return fail(404, 'not_found', 'Diese Version gibt es nicht.');
    if (req.method === 'GET') return ok(versionView(id, list, v));
    if (req.method === 'DELETE') {
      if (v === list.at(-1))
        return fail(409, 'not_deletable', 'Die neueste Version bleibt immer erhalten.');
      if (runs(db, id, v.version).total > 0)
        return fail(409, 'not_deletable', 'Ein Durchlauf verweist noch darauf.');
      defs[id] = list.filter((x) => x !== v);
      persist(db);
      return ok(null, 204);
    }
  }
  return fail(404, 'not_found', 'Diese Adresse gibt es in der Demo nicht.');
}

export const demoBackend: HttpInterceptorFn = (req, next) => {
  const url = new URL(req.urlWithParams, 'http://demo');
  if (!url.pathname.startsWith('/api/')) {
    return next(req);
  }
  const path = url.pathname.slice(4).split('/').filter(Boolean).map(decodeURIComponent);
  const db = load();

  switch (`${req.method} ${path[0]}`) {
    case 'GET actions':
      return ok({ actions: DEMO_ACTIONS });
    case 'GET data-models':
      return ok({
        models:
          url.searchParams.get('writable') === '1'
            ? DEMO_MODELS.filter((m) => m.fields.some((f) => f.writable))
            : DEMO_MODELS,
      });
    case 'GET upload-handlers':
      return ok({ handlers: DEMO_UPLOAD_HANDLERS });
    case 'GET templates': {
      const type = url.searchParams.get('type');
      return path[1]
        ? ok(DEMO_TEMPLATES.find((t) => t.id === path[1]))
        : ok({
            templates: DEMO_TEMPLATES.filter((t) => !type || t.type === type).map(
              ({ id, name, type: tt }) => ({ id, name, type: tt }),
            ),
          });
    }
  }
  if (path[0] === 'instances') {
    return instances(db, url, req, path);
  }
  if (path[0] === 'workflows') {
    return workflows(db, req, path);
  }
  return fail(404, 'not_found', 'Diese Adresse gibt es in der Demo nicht.');
};
