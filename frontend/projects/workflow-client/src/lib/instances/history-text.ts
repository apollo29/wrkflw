import type { HistoryEntry } from '../api/models';

export interface HistoryLine {
  readonly at: string;
  readonly text: string;
  readonly error: boolean;
}

export type Translate = (key: string, params?: Record<string, string | number>) => string;

function names(value: unknown): string {
  return Array.isArray(value)
    ? value.filter((v): v is string => typeof v === 'string').join(', ')
    : '';
}

function text(value: unknown): string {
  return typeof value === 'string' || typeof value === 'number' ? String(value) : '';
}

/**
 * Verlauf in Sätzen (F-90). Der Verlauf enthält nur Namen und technische Angaben, nie
 * Werte (F-91); hier wird nichts anderes ausgegeben. Eine Aktion und der folgende
 * Übergang desselben Schritts werden zu einer Zeile «x ausgeführt → y».
 */
export function describeHistory(entries: readonly HistoryEntry[], t: Translate): HistoryLine[] {
  const out: HistoryLine[] = [];
  for (let i = 0; i < entries.length; i++) {
    const e = entries[i]!;
    const d = e.detail;
    const step = e.step ?? '';
    const line = (
      key: string,
      params: Record<string, string | number> = {},
      error = false,
    ): void => {
      out.push({ at: e.at, text: t(key, { step, ...params }), error });
    };
    switch (e.kind) {
      case 'action': {
        const next = entries[i + 1];
        if (next?.kind === 'transition' && next.step === e.step) {
          line('history.actionTo', { to: text(next.detail['to']), keys: names(d['resultKeys']) });
          i++;
        } else {
          line('history.action', { keys: names(d['resultKeys']) });
        }
        break;
      }
      case 'start':
        line('history.start', { keys: names(d['contextKeys']) });
        break;
      case 'transition':
      case 'back':
        line(`history.${e.kind}`, { to: text(d['to']) });
        break;
      case 'event':
        line('history.event', { event: text(d['event']), keys: names(d['payloadKeys']) });
        break;
      case 'event_duplicate':
      case 'event_payload_rejected':
        line(`history.${e.kind}`, { event: text(d['event']), keys: names(d['dropped']) });
        break;
      case 'retry':
        line('history.retry', { attempt: text(d['attempt']), max: text(d['maxAttempts']) });
        break;
      case 'error':
        line('history.error', { message: text(d['message']) }, true);
        break;
      case 'subworkflow_done':
        line('history.subworkflow_done', { status: t(`status.instance.${text(d['status'])}`) });
        break;
      case 'cancelled':
        line(d['reason'] === 'parent_cancelled' ? 'history.cancelled_parent' : 'history.cancelled');
        break;
      case 'wait_event':
      case 'wait_timer':
      case 'wait_subworkflow':
      case 'complete':
        line(`history.${e.kind}`);
        break;
      default:
        line('history.other', { kind: e.kind });
    }
  }
  return out;
}
