import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { CurrentStep } from '../api/models';
import { provideWorkflowAdmin, WfUploadFile } from '../config/config';
import { RUNNER_POLL_MS, WfWorkflowRunner } from './workflow-runner';

const STEP: CurrentStep = {
  instanceId: 'i-1',
  status: 'waiting_event',
  step: 'pruefen',
  type: 'interactive',
  interactive: true,
  finished: false,
  ui: {
    title: 'Antrag prüfen',
    description: 'Bitte prüfen.',
    template: 'hinweis',
    fields: [
      { name: 'bemerkung', label: 'Bemerkung' },
      { name: 'ok', label: 'Geprüft', type: 'boolean' },
      { name: 'betrag', label: 'Betrag', type: 'display' },
    ],
  },
  events: [
    { event: 'submit', label: 'Freigeben' },
    { event: 'ablehnen', label: 'Ablehnen' },
  ],
  canGoBack: false,
  context: { betrag: 1250, name: '<i>Anna</i>' },
};

describe('WfWorkflowRunner', () => {
  let http: HttpTestingController;

  function setup(upload?: WfUploadFile) {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideWorkflowAdmin({ apiBaseUrl: '/api', ...(upload ? { uploadFile: upload } : {}) }),
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(WfWorkflowRunner);
    fixture.componentRef.setInput('instanceId', 'i-1');
    return { fixture, el: fixture.nativeElement as HTMLElement };
  }

  async function answerStep(
    fixture: { whenStable(): Promise<unknown> },
    step: CurrentStep,
    template = true,
  ): Promise<void> {
    await fixture.whenStable();
    http.expectOne('/api/instances/i-1/current-step').flush(step);
    await Promise.resolve();
    if (template && step.interactive && step.ui['template']) {
      http.expectOne('/api/templates/hinweis').flush({
        id: 'hinweis',
        name: 'H',
        type: 'page',
        subject: '',
        body: '<p>Hallo {{name}}</p>',
      });
    }
    await fixture.whenStable();
  }

  const button = (el: HTMLElement, event: string) =>
    el.querySelector<HTMLButtonElement>(`[data-event="${event}"]`)!;

  it('renders title, escaped page template, fields and one button per event (F-50 to F-53)', async () => {
    const { fixture, el } = setup();
    await answerStep(fixture, STEP);
    expect(el.querySelector('h2')?.textContent).toContain('Antrag prüfen');
    expect(el.querySelector('[data-testid="runner-page"]')?.innerHTML).toBe(
      '<p>Hallo &lt;i&gt;Anna&lt;/i&gt;</p>',
    );
    expect(el.querySelector('[data-testid="runner-display"]')?.textContent).toBe('1250');
    expect(button(el, 'submit').textContent?.trim()).toBe('Freigeben');
    expect(button(el, 'submit').classList).toContain('wf-btn--primary');
    expect(el.querySelector('[data-testid="runner-back"]')).toBeNull();
  });

  it('sends input fields only, with an idempotency key, and loads the next step', async () => {
    const { fixture, el } = setup();
    await answerStep(fixture, STEP);
    const input = el.querySelector<HTMLInputElement>('[data-field="bemerkung"]')!;
    input.value = 'passt';
    input.dispatchEvent(new Event('input'));
    button(el, 'submit').click();
    button(el, 'submit').click(); // Doppelklick: nur ein Ereignis
    const req = http.expectOne('/api/instances/i-1/events');
    expect(req.request.body).toEqual({
      event: 'submit',
      payload: { bemerkung: 'passt', ok: false },
    });
    expect(req.request.headers.get('Idempotency-Key')).toMatch(/.{8,}/);
    req.flush({ id: 'i-1', status: 'completed', currentStep: 'ende' });
    await Promise.resolve();
    const finished = vi.fn();
    fixture.componentInstance.finished.subscribe(finished);
    await answerStep(fixture, {
      ...STEP,
      status: 'completed',
      finished: true,
      interactive: false,
      step: 'ende',
      ui: {},
    });
    expect(el.querySelector('[data-testid="runner-state"]')?.textContent).toContain(
      'Der Ablauf ist abgeschlossen.',
    );
    expect(finished).toHaveBeenCalled();
  });

  it('retries with the same key after a network error (Q-04)', async () => {
    const { fixture, el } = setup();
    await answerStep(fixture, STEP);
    button(el, 'ablehnen').click();
    const first = http.expectOne('/api/instances/i-1/events');
    const key = first.request.headers.get('Idempotency-Key');
    first.error(new ProgressEvent('error'));
    await fixture.whenStable();
    expect(el.querySelector('[data-testid="runner-error"]')).not.toBeNull();

    [...el.querySelectorAll('button')]
      .find((b) => b.textContent?.includes('Erneut versuchen'))!
      .click();
    const second = http.expectOne('/api/instances/i-1/events');
    expect(second.request.headers.get('Idempotency-Key')).toBe(key);
    expect(second.request.body).toMatchObject({ event: 'ablehnen' });
  });

  it('offers «Ein Schritt zurück» only when possible (F-56)', async () => {
    const { fixture, el } = setup();
    await answerStep(fixture, { ...STEP, step: 'zwei', canGoBack: true, ui: { title: 'Zwei' } });
    el.querySelector<HTMLButtonElement>('[data-testid="runner-back"]')!.click();
    http
      .expectOne((r) => r.method === 'POST' && r.url === '/api/instances/i-1/back')
      .flush({ id: 'i-1', status: 'waiting_event', currentStep: 'pruefen' });
    await Promise.resolve();
    await answerStep(fixture, STEP);
    expect(el.querySelector('h2')?.textContent).toContain('Antrag prüfen');
  });

  it('polls while running and shows waiting states (F-22, F-23)', async () => {
    const { fixture, el } = setup();
    await answerStep(fixture, { ...STEP, status: 'running', interactive: false, ui: {} });
    expect(el.querySelector('[data-testid="runner-state"]')?.textContent).toContain(
      'Läuft im Hintergrund',
    );
    await new Promise((resolve) => setTimeout(resolve, RUNNER_POLL_MS + 50));
    await answerStep(fixture, {
      ...STEP,
      status: 'waiting_timer',
      interactive: false,
      type: 'timer',
      ui: {},
    });
    expect(el.querySelector('[data-testid="runner-state"]')?.textContent).toContain(
      'Wartet auf den nächsten Termin',
    );
    await new Promise((resolve) => setTimeout(resolve, RUNNER_POLL_MS + 50));
    http.expectNone('/api/instances/i-1/current-step');
  });

  it('uploads files through the host and shows a rejection at the field (F-54)', async () => {
    const upload = vi
      .fn<WfUploadFile>()
      .mockResolvedValueOnce({ rejected: 'Nur PDF bis 5 MB.' })
      .mockResolvedValueOnce({ value: 'datei-7' });
    const { fixture, el } = setup(upload);
    const fileStep = {
      ...STEP,
      ui: { fields: [{ name: 'beleg', label: 'Beleg', type: 'file', handler: 'pdf' }] },
    };
    await answerStep(fixture, fileStep);

    const input = el.querySelector<HTMLInputElement>('[data-field="beleg"]')!;
    Object.defineProperty(input, 'files', {
      value: [new File(['x'], 'bild.png')],
      configurable: true,
    });
    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(upload).toHaveBeenCalledWith(expect.any(File), {
      handler: 'pdf',
      instanceId: 'i-1',
      step: 'pruefen',
      field: 'beleg',
    });
    expect(el.textContent).toContain('Nur PDF bis 5 MB.');

    Object.defineProperty(input, 'files', {
      value: [new File(['x'], 'beleg.pdf')],
      configurable: true,
    });
    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(el.textContent).toContain('Hochgeladen: beleg.pdf');
    button(el, 'submit').click();
    expect(http.expectOne('/api/instances/i-1/events').request.body).toEqual({
      event: 'submit',
      payload: { beleg: 'datei-7' },
    });
  });

  it('explains file fields without an upload function', async () => {
    const { fixture, el } = setup();
    await answerStep(fixture, { ...STEP, ui: { fields: [{ name: 'beleg', type: 'file' }] } });
    expect(el.textContent).toContain('Datei-Upload nur über die Seite der Anwendung.');
    expect(el.querySelector<HTMLInputElement>('[data-field="beleg"]')!.disabled).toBe(true);
  });

  it('shows an error with retry when loading fails', async () => {
    const { fixture, el } = setup();
    await fixture.whenStable();
    http
      .expectOne('/api/instances/i-1/current-step')
      .flush(
        { error: { code: 'not_found', message: 'weg' } },
        { status: 404, statusText: 'Not Found' },
      );
    await Promise.resolve();
    await fixture.whenStable();
    expect(el.querySelector('[data-testid="runner-error"]')?.textContent).toContain(
      'Nicht gefunden.',
    );
  });
});
