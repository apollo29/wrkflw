import type { HistoryEntry } from '../api/models';
import { describeHistory } from './history-text';

const t = (
  kind: string,
  step: string | null,
  detail: Record<string, unknown> = {},
): HistoryEntry => ({
  kind,
  step,
  detail,
  at: '2026-10-30T16:31:00Z',
});

describe('describeHistory (F-90, F-91)', () => {
  const lines = (entries: HistoryEntry[]) =>
    describeHistory(entries, (key, params) => `${key}|${JSON.stringify(params ?? {})}`).map(
      (l) => l.text,
    );

  it('merges an action with the following transition', () => {
    expect(
      lines([
        t('action', 'daten_laden', { action: 'check_data', resultKeys: ['antrag'] }),
        t('transition', 'daten_laden', { to: 'pruefen' }),
      ]),
    ).toEqual(['history.actionTo|{"step":"daten_laden","to":"pruefen","keys":"antrag"}']);
  });

  it('describes every kind with names only', () => {
    const result = describeHistory(
      [
        t('start', 'a', { contextKeys: ['antrag_id'] }),
        t('wait_event', 'pruefen'),
        t('wait_timer', 'warten', { wakeAt: '2026-11-01T09:14:00Z' }),
        t('wait_subworkflow', 'abschluss', { childId: 'c1' }),
        t('subworkflow_done', 'abschluss', { childId: 'c1', status: 'completed' }),
        t('event', 'pruefen', { event: 'submit', payloadKeys: ['bemerkung'] }),
        t('event_duplicate', 'pruefen', { event: 'submit', eventId: 'k1' }),
        t('event_payload_rejected', 'pruefen', { event: 'submit', dropped: ['x'] }),
        t('retry', 'mail', { attempt: 1, maxAttempts: 3 }),
        t('error', 'mail', { message: 'Zeitüberschreitung' }),
        t('back', 'pruefen', { to: 'erfassen' }),
        t('cancelled', 'pruefen', { reason: 'manual' }),
        t('cancelled', 'kind', { reason: 'parent_cancelled' }),
        t('complete', 'ende'),
        t('transition', 'a', { to: 'b' }),
        t('unbekannt', 'x'),
      ],
      (key, params) => `${key}${params ? ' ' + JSON.stringify(params) : ''}`,
    );
    expect(result.map((l) => l.text.split(' ')[0])).toEqual([
      'history.start',
      'history.wait_event',
      'history.wait_timer',
      'history.wait_subworkflow',
      'history.subworkflow_done',
      'history.event',
      'history.event_duplicate',
      'history.event_payload_rejected',
      'history.retry',
      'history.error',
      'history.back',
      'history.cancelled',
      'history.cancelled_parent',
      'history.complete',
      'history.transition',
      'history.other',
    ]);
    expect(result.filter((l) => l.error).map((l) => l.text.split(' ')[0])).toEqual([
      'history.error',
    ]);
    expect(result[5]?.text).toContain('"keys":"bemerkung"');
  });
});
