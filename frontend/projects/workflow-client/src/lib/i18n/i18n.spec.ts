import { TestBed } from '@angular/core/testing';
import { provideWorkflowAdmin } from '../config/config';
import { formatMessage, WfI18n, WfTranslatePipe } from './i18n';
import { MESSAGES_DE } from './messages.de';

describe('i18n', () => {
  it('fills placeholders and keeps unknown ones', () => {
    expect(formatMessage('Phase {phase} von {n}', { phase: 7 })).toBe('Phase 7 von {n}');
    expect(formatMessage('ohne', undefined)).toBe('ohne');
  });

  it('uses the German catalog by default', () => {
    const i18n = TestBed.inject(WfI18n);
    expect(i18n.t('nav.instances')).toBe('Instanzen');
    expect(i18n.t('page.placeholder', { phase: 8 })).toBe('Dieser Bereich folgt in Phase 8.');
    expect(i18n.tDynamic('status.instance.waiting_timer')).toBe('Wartet auf den nächsten Termin');
    expect(i18n.tDynamic('gibt.es.nicht')).toBe('gibt.es.nicht');
  });

  it('lets the host replace single texts', () => {
    TestBed.configureTestingModule({
      providers: [
        provideWorkflowAdmin({ apiBaseUrl: '/api', messages: { 'nav.instances': 'Läufe' } }),
      ],
    });
    const i18n = TestBed.inject(WfI18n);
    expect(i18n.t('nav.instances')).toBe('Läufe');
    expect(i18n.t('nav.templates')).toBe('Vorlagen');
    expect(
      TestBed.runInInjectionContext(() => new WfTranslatePipe()).transform('nav.instances'),
    ).toBe('Läufe');
  });

  it('has no empty texts', () => {
    const empty = Object.entries(MESSAGES_DE).filter(([, text]) => text.trim() === '');
    expect(empty).toEqual([]);
  });
});
