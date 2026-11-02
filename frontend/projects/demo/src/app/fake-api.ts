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
  DEMO_MODELS,
  DEMO_TEMPLATES,
  DEMO_UPLOAD_HANDLERS,
} from './demo-data';

/*
 * Ersatz-Backend der Demo im Browser (bis Phase 12 das echte Backend anbindet). Bildet die
 * Routen nach, die der Client bisher braucht; Zustand in localStorage.
 */

const KEY = 'wrkflw-demo-v1';

interface StoredVersion {
  version: number;
  status: 'active' | 'inactive' | 'draft';
  definition: Record<string, unknown>;
  createdAt: string;
  updatedAt: string;
}

type Db = Record<string, StoredVersion[]>;

function now(): string {
  return new Date().toISOString();
}

function seed(): Db {
  const db: Db = {};
  for (const definition of DEMO_DEFINITIONS) {
    db[definition['id'] as string] = [
      { version: 1, status: 'active', definition, createdAt: now(), updatedAt: now() },
    ];
  }
  return db;
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

interface ValidationError {
  code: string;
  message: string;
  step: string | null;
  transition: number | null;
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
  return {
    startStep: definition['startStep'],
    steps: Object.entries(steps).map(([name, s]) => ({
      name,
      kind: s['type'] === 'interactive' || s['type'] === 'timer' ? s['type'] : 'automatic',
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

export const demoBackend: HttpInterceptorFn = (req: HttpRequest<unknown>, next) => {
  const url = new URL(req.urlWithParams, 'http://demo');
  if (!url.pathname.startsWith('/api/')) {
    return next(req);
  }
  const path = url.pathname.slice(4).split('/').filter(Boolean).map(decodeURIComponent);
  const db = load();
  const body = (req.body ?? {}) as Record<string, unknown>;

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
    case 'GET instances':
      return ok({
        instances: [],
        counts: {
          running: 0,
          waiting_event: 0,
          waiting_timer: 0,
          completed: 0,
          failed: 0,
          cancelled: 0,
        },
      });
  }

  if (path[0] !== 'workflows') {
    return fail(404, 'not_found', 'Diese Adresse gibt es in der Demo nicht.');
  }

  if (req.method === 'POST' && path[1] === 'validate') {
    const errors = validate((body['definition'] ?? {}) as Record<string, unknown>);
    return ok({ valid: errors.length === 0, errors });
  }

  if (req.method === 'GET' && path.length === 1) {
    return ok({
      definitions: Object.entries(db).map(([id, list]) => {
        const latest = list.at(-1)!;
        return {
          id,
          name: (latest.definition['name'] as string) || id,
          version: latest.version,
          status: latest.status,
          updatedAt: latest.updatedAt,
          runningInstances: 0,
          totalInstances: 0,
          olderVersions: list.length - 1,
          structure: structure(latest.definition),
        };
      }),
    });
  }

  const id = path[1] ?? '';
  const list = db[id];

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
    if (latest && status !== 'active') {
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
    db[id] = versions;
    persist(db);
    return ok(
      { id, version: saved.version, status, newVersion: saved !== latest, validation: errors },
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
      versions: [...list].reverse().map((v, i) => ({
        version: v.version,
        name: (v.definition['name'] as string) || id,
        status: v.status,
        createdAt: v.createdAt,
        updatedAt: v.updatedAt,
        runningInstances: 0,
        totalInstances: 0,
        deletable: i > 0,
        deleteBlockedBy: i === 0 ? 'latest' : null,
      })),
    });
  }
  if (path[2] === 'versions' && path[3]) {
    const v = list.find((x) => x.version === Number(path[3]));
    if (!v) return fail(404, 'not_found', 'Diese Version gibt es nicht.');
    if (req.method === 'GET') return ok(versionView(id, list, v));
    if (req.method === 'DELETE') {
      if (v === list.at(-1))
        return fail(409, 'not_deletable', 'Die neueste Version bleibt immer erhalten.');
      db[id] = list.filter((x) => x !== v);
      persist(db);
      return ok(null, 204);
    }
  }
  return fail(404, 'not_found', 'Diese Adresse gibt es in der Demo nicht.');
};
