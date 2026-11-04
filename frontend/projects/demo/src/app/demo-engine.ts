/*
 * Sehr kleine Nachbildung der Engine für die Demo: Schritte ausführen, auf Ereignisse
 * warten, Bedingungen der Form context['x'] OP wert prüfen, «zurück». Nur für die Demo;
 * die echte Logik liegt im PHP-Backend.
 */

export type Status =
  'running' | 'waiting_event' | 'waiting_timer' | 'completed' | 'failed' | 'cancelled';

export interface HistoryRow {
  kind: string;
  step: string | null;
  detail: Record<string, unknown>;
  at: string;
}

export interface EngineInstance {
  id: string;
  currentStep: string;
  status: Status;
  wakeAt: string | null;
  updatedAt: string;
  history: HistoryRow[];
  context?: Record<string, unknown>;
  applied?: string[];
}

type Step = Record<string, unknown>;
type Transition = Record<string, unknown>;

const now = (): string => new Date().toISOString();

function steps(definition: Record<string, unknown>): Record<string, Step> {
  return (definition['steps'] ?? {}) as Record<string, Step>;
}

function transitions(step: Step | undefined): Transition[] {
  return ((step?.['transitions'] ?? []) as Transition[]).filter((t) => typeof t['to'] === 'string');
}

function ui(step: Step | undefined): Record<string, unknown> {
  return (step?.['ui'] ?? {}) as Record<string, unknown>;
}

/** `true`, leer oder `context['x'] OP literal`; alles andere gilt als falsch. */
export function evaluate(when: unknown, context: Record<string, unknown>): boolean {
  if (when === undefined || when === null || when === '' || when === 'true') {
    return true;
  }
  const m = /^context\['([^']+)'\]\s*(==|!=|>=|<=|>|<)\s*(.+)$/.exec(String(when).trim());
  if (!m) {
    return false;
  }
  const raw = m[3]!.trim();
  const right: unknown =
    raw === 'true'
      ? true
      : raw === 'false'
        ? false
        : /^-?\d+(\.\d+)?$/.test(raw)
          ? Number(raw)
          : raw.replace(/^'(.*)'$/, '$1');
  const left = context[m[1]!];
  switch (m[2]) {
    case '==':
      return left === right || String(left) === String(right);
    case '!=':
      return !(left === right || String(left) === String(right));
    case '>':
      return Number(left) > Number(right);
    case '<':
      return Number(left) < Number(right);
    case '>=':
      return Number(left) >= Number(right);
    default:
      return Number(left) <= Number(right);
  }
}

function log(
  i: EngineInstance,
  kind: string,
  step: string | null,
  detail: Record<string, unknown> = {},
): void {
  i.history.push({ kind, step, detail, at: now() });
}

/** Führt automatische Schritte aus, bis der Durchlauf wartet oder endet. */
export function advance(i: EngineInstance, definition: Record<string, unknown>): void {
  const all = steps(definition);
  const ctx = (i.context ??= {});
  for (let guard = 0; guard < 50; guard++) {
    const step = all[i.currentStep];
    if (!step) {
      i.status = 'failed';
      log(i, 'error', i.currentStep, { message: `Schritt '${i.currentStep}' fehlt.` });
      break;
    }
    const type = (step['type'] as string) ?? 'automatic';
    if (type === 'interactive') {
      i.status = 'waiting_event';
      log(i, 'wait_event', i.currentStep);
      break;
    }
    if (type === 'timer') {
      i.status = 'waiting_timer';
      i.wakeAt = new Date(Date.now() + Number(step['delaySeconds'] ?? 3600) * 1000).toISOString();
      log(i, 'wait_timer', i.currentStep);
      break;
    }
    const action = step['action'] as string | undefined;
    const config = (step['config'] ?? {}) as Record<string, unknown>;
    const keys: string[] = [];
    if (action === 'check_data') {
      const as = (config['as'] as string) || 'checkedValue';
      Object.assign(ctx, {
        [as]: 'offen',
        [`${as}Found`]: true,
        [`${as}_betrag`]: 1250,
        [`${as}_mail`]: 'person@example.org',
      });
      keys.push(as, `${as}Found`);
    } else if (action === 'write_data') {
      const as = (config['as'] as string) || 'written';
      Object.assign(ctx, { [as]: true, [`${as}Count`]: 1 });
      keys.push(as);
    } else if (action === 'start_workflow') {
      ctx['startedWorkflow'] = { definitionId: config['workflowId'] };
      keys.push('startedWorkflow');
    }
    log(i, 'action', i.currentStep, { action, resultKeys: keys });
    const next = transitions(step).find((t) => !t['event'] && evaluate(t['when'], ctx));
    if (!next) {
      i.status = 'completed';
      log(i, 'complete', i.currentStep);
      break;
    }
    log(i, 'transition', i.currentStep, { to: next['to'] });
    i.currentStep = next['to'] as string;
  }
  i.updatedAt = now();
}

/** Ein Ereignis am interaktiven Schritt; doppelte Schlüssel wirken nicht (Q-04). */
export function handleEvent(
  i: EngineInstance,
  definition: Record<string, unknown>,
  event: string,
  payload: Record<string, unknown>,
  key: string | null,
): 'ok' | 'duplicate' | 'not_waiting' {
  if (key && i.applied?.includes(key)) {
    log(i, 'event_duplicate', i.currentStep, { event, eventId: key });
    return 'duplicate';
  }
  const step = steps(definition)[i.currentStep];
  if (i.status !== 'waiting_event' || step?.['type'] !== 'interactive') {
    return 'not_waiting';
  }
  if (key) {
    (i.applied ??= []).push(key);
  }
  const inputs = ((ui(step)['fields'] ?? []) as Record<string, unknown>[])
    .filter((f) => f['type'] !== 'display')
    .map((f) => f['name'] as string);
  const accepted = Object.fromEntries(Object.entries(payload).filter(([k]) => inputs.includes(k)));
  Object.assign((i.context ??= {}), accepted);
  log(i, 'event', i.currentStep, { event, payloadKeys: Object.keys(accepted) });
  const next = transitions(step).find(
    (t) => t['event'] === event && evaluate(t['when'], i.context!),
  );
  if (next) {
    log(i, 'transition', i.currentStep, { to: next['to'] });
    i.currentStep = next['to'] as string;
    i.status = 'running';
    advance(i, definition);
  }
  i.updatedAt = now();
  return 'ok';
}

/** Letzter besuchter interaktiver Schritt vor dem aktuellen (F-56). */
function backTarget(i: EngineInstance, definition: Record<string, unknown>): string | null {
  const step = steps(definition)[i.currentStep];
  if (i.status !== 'waiting_event' || ui(step)['back'] !== true) {
    return null;
  }
  for (let k = i.history.length - 1; k >= 0; k--) {
    const h = i.history[k]!;
    if (h.kind === 'wait_event' && h.step && h.step !== i.currentStep) {
      return h.step;
    }
  }
  return null;
}

export function canGoBack(i: EngineInstance, definition: Record<string, unknown>): boolean {
  return backTarget(i, definition) !== null;
}

export function goBack(i: EngineInstance, definition: Record<string, unknown>): boolean {
  const target = backTarget(i, definition);
  if (!target) {
    return false;
  }
  log(i, 'back', i.currentStep, { to: target });
  i.currentStep = target;
  log(i, 'wait_event', target);
  i.updatedAt = now();
  return true;
}

/** Antwort von `GET /instances/:id/current-step`. */
export function currentStep(
  i: EngineInstance & { id: string },
  definition: Record<string, unknown>,
) {
  const step = steps(definition)[i.currentStep];
  const type = ((step?.['type'] as string) ?? 'automatic') as 'automatic' | 'interactive' | 'timer';
  const seen = new Map<string, string>();
  if (type === 'interactive') {
    for (const t of transitions(step)) {
      const event = t['event'];
      if (typeof event === 'string' && event !== '' && !seen.has(event)) {
        seen.set(
          event,
          typeof t['label'] === 'string' && t['label'] !== '' ? (t['label'] as string) : event,
        );
      }
    }
  }
  return {
    instanceId: i.id,
    status: i.status,
    step: i.currentStep,
    type,
    interactive: type === 'interactive',
    finished: ['completed', 'failed', 'cancelled'].includes(i.status),
    ui: ui(step),
    events: [...seen].map(([event, label]) => ({ event, label })),
    canGoBack: canGoBack(i, definition),
    context: Object.fromEntries(
      Object.entries(i.context ?? {}).filter(([k]) => !k.startsWith('__')),
    ),
  };
}
