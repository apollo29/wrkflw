import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { provideWorkflowAdmin } from '../config/config';
import { WfDialogService } from '../ui/dialog';
import { WfTemplateManager } from './template-manager';

const TEMPLATE = {
  id: 'antrag-ok',
  name: 'Antrag bestätigt',
  type: 'email',
  subject: 'Antrag {{antrag_id}}',
  body: '<p>Hallo {{name}}</p>',
};

const DEFINITION = {
  id: 'antrag',
  startStep: 'bestaetigung',
  inputs: [{ name: 'antrag_id', label: 'Antrag', required: true, beispiel: 'A-1024' }],
  steps: {
    bestaetigung: {
      type: 'automatic',
      action: 'send_email',
      config: { templateId: 'antrag-ok' },
      transitions: [],
    },
  },
};

describe('WfTemplateManager', () => {
  let http: HttpTestingController;
  let confirm: ReturnType<typeof vi.fn>;

  async function setup(
    url = '/vorlagen',
    list = [{ id: 'antrag-ok', name: 'Antrag bestätigt', type: 'email' }],
  ) {
    confirm = vi.fn().mockResolvedValue(true);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideWorkflowAdmin({ apiBaseUrl: '/api' }),
        provideRouter([{ path: 'vorlagen', component: WfTemplateManager }]),
        { provide: WfDialogService, useValue: { confirm } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const harness = await RouterTestingHarness.create(url);
    const type = new URL(url, 'http://x').searchParams.get('type') ?? 'email';
    http
      .expectOne((r) => r.url === '/api/templates' && r.params.get('type') === type)
      .flush({ templates: list });
    await settle(harness);
    return { harness, el: harness.routeNativeElement as HTMLElement };
  }

  async function settle(harness: RouterTestingHarness): Promise<void> {
    for (let i = 0; i < 4; i++) {
      await harness.fixture.whenStable();
      harness.detectChanges();
    }
  }

  function q<T extends HTMLElement>(el: HTMLElement, testid: string): T | null {
    return el.querySelector<T>(`[data-testid="${testid}"]`);
  }

  function type(input: HTMLInputElement, value: string): void {
    input.value = value;
    input.dispatchEvent(new Event('input'));
  }

  async function openTemplate(
    harness: RouterTestingHarness,
    usage = [{ definitionId: 'antrag', version: 3, step: 'bestaetigung' }],
  ) {
    http.expectOne('/api/templates/antrag-ok').flush(TEMPLATE);
    await settle(harness);
    http.expectOne('/api/templates/antrag-ok/usage').flush({ templateId: 'antrag-ok', usage });
    http
      .expectOne('/api/workflows')
      .flush({ definitions: [{ id: 'antrag', name: 'Antrag prüfen', version: 3 }] });
    await settle(harness);
    if (usage.length > 0) {
      http
        .expectOne('/api/workflows/antrag/versions/3')
        .flush({ id: 'antrag', version: 3, definition: DEFINITION });
    }
    await settle(harness);
  }

  afterEach(() => http.verify());

  it('lists templates per type and shows an empty state', async () => {
    const { harness, el } = await setup();
    expect(el.querySelector('[data-template="antrag-ok"]')?.textContent).toContain(
      'Antrag bestätigt',
    );
    expect(el.textContent).toContain('Wähle links eine Vorlage');

    el.querySelectorAll<HTMLButtonElement>('wf-segment button')[1]?.click();
    await settle(harness);
    http
      .expectOne((r) => r.url === '/api/templates' && r.params.get('type') === 'page')
      .flush({ templates: [] });
    await settle(harness);
    expect(TestBed.inject(Router).url).toContain('type=page');
    expect(el.textContent).toContain('Noch keine Vorlagen.');
  });

  it('opens a template with usage, workflow names and placeholder suggestions', async () => {
    const { harness, el } = await setup('/vorlagen?id=antrag-ok');
    await openTemplate(harness);

    expect(el.querySelector('[data-template="antrag-ok"]')?.getAttribute('aria-current')).toBe(
      'true',
    );
    expect(q<HTMLInputElement>(el, 'templates-id')?.readOnly).toBe(true);
    expect(q<HTMLInputElement>(el, 'templates-subject')?.value).toBe('Antrag {{antrag_id}}');
    const usage = q(el, 'templates-usage')!;
    expect(usage.textContent).toContain('Verwendet in 1 Schritt(en):');
    expect(usage.textContent).toContain('Antrag prüfen');
    expect(usage.textContent).toContain('bestaetigung');
    expect(usage.querySelector('a')?.getAttribute('href')).toBe('/workflows/antrag');
    const options = [...el.querySelectorAll('wf-html-editor select option')].map((o) =>
      o.textContent?.trim(),
    );
    expect(options).toContain('antrag_id');
    expect(q(el, 'templates-subject-preview')?.textContent).toContain('Betreff: Antrag A-1024');
    expect(q(el, 'templates-dirty')).toBeNull();
  });

  it('creates a template, checks the ID and saves with 201', async () => {
    const { harness, el } = await setup('/vorlagen', []);
    q<HTMLButtonElement>(el, 'templates-new')!.click();
    await settle(harness);
    expect(q(el, 'templates-delete')).toBeNull();

    q<HTMLButtonElement>(el, 'templates-save')!.click();
    await settle(harness);
    expect(el.textContent).toContain('Bitte eine ID angeben.');

    type(q<HTMLInputElement>(el, 'templates-id')!, 'neu ung');
    q<HTMLButtonElement>(el, 'templates-save')!.click();
    await settle(harness);
    expect(el.textContent).toContain('Die ID besteht aus Buchstaben');

    type(q<HTMLInputElement>(el, 'templates-id')!, 'neu');
    type(q<HTMLInputElement>(el, 'templates-name')!, 'Neue Vorlage');
    await settle(harness);
    expect(q(el, 'templates-dirty')).not.toBeNull();
    q<HTMLButtonElement>(el, 'templates-save')!.click();
    await settle(harness);
    const req = http.expectOne('/api/templates/neu');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toMatchObject({ name: 'Neue Vorlage', type: 'email', subject: '' });
    req.flush(
      { id: 'neu', name: 'Neue Vorlage', type: 'email', subject: '', body: '' },
      { status: 201, statusText: 'Created' },
    );
    await settle(harness);
    http
      .expectOne((r) => r.url === '/api/templates')
      .flush({ templates: [{ id: 'neu', name: 'Neue Vorlage', type: 'email' }] });
    await settle(harness);
    // Nach dem Speichern steht die Auswahl in der Adresse und lädt die Vorlage.
    expect(TestBed.inject(Router).url).toContain('id=neu');
    http
      .expectOne('/api/templates/neu')
      .flush({ id: 'neu', name: 'Neue Vorlage', type: 'email', subject: '', body: '' });
    await settle(harness);
    http.expectOne('/api/templates/neu/usage').flush({ templateId: 'neu', usage: [] });
    http.expectOne('/api/workflows').flush({ definitions: [] });
    await settle(harness);
    expect(q(el, 'templates-usage')?.textContent).toContain(
      'Wird derzeit von keinem Workflow verwendet.',
    );
    expect(q(el, 'templates-dirty')).toBeNull();
  });

  it('shows field errors from the engine', async () => {
    const { harness, el } = await setup('/vorlagen', []);
    q<HTMLButtonElement>(el, 'templates-new')!.click();
    await settle(harness);
    type(q<HTMLInputElement>(el, 'templates-id')!, 'x');
    q<HTMLButtonElement>(el, 'templates-save')!.click();
    await settle(harness);
    http.expectOne('/api/templates/x').flush(
      {
        error: {
          code: 'validation_failed',
          message: 'Ungültig',
          details: { fields: { id: ['ID schon vergeben.'] } },
        },
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    await settle(harness);
    expect(el.textContent).toContain('ID schon vergeben.');
  });

  it('asks before deleting, names the usage and shows a 409 reason', async () => {
    const { harness, el } = await setup('/vorlagen?id=antrag-ok');
    await openTemplate(harness);
    q<HTMLButtonElement>(el, 'templates-delete')!.click();
    await settle(harness);
    expect(confirm.mock.calls[0]?.[0].text).toContain('1 Schritt(en)');
    const req = http.expectOne('/api/templates/antrag-ok');
    expect(req.request.method).toBe('DELETE');
    req.flush(
      { error: { code: 'in_use', message: 'Vorlage wird noch in antrag v3 verwendet.' } },
      { status: 409, statusText: 'Conflict' },
    );
    await settle(harness);
    expect(q(el, 'templates-message')?.textContent).toContain('verwendet');
  });

  it('deletes an unused template after confirmation', async () => {
    const { harness, el } = await setup('/vorlagen?id=antrag-ok');
    await openTemplate(harness, []);
    q<HTMLButtonElement>(el, 'templates-delete')!.click();
    await settle(harness);
    expect(confirm.mock.calls[0]?.[0].text).toBe('Die Vorlage wird endgültig gelöscht.');
    http
      .expectOne('/api/templates/antrag-ok')
      .flush(null, { status: 204, statusText: 'No Content' });
    await settle(harness);
    http.expectOne((r) => r.url === '/api/templates').flush({ templates: [] });
    await settle(harness);
    expect(TestBed.inject(Router).url).not.toContain('id=');
    expect(q(el, 'templates-message')?.textContent).toContain('Gelöscht: antrag-ok.');
  });

  it('asks before switching away from unsaved changes', async () => {
    const { harness, el } = await setup('/vorlagen?id=antrag-ok');
    await openTemplate(harness);
    type(q<HTMLInputElement>(el, 'templates-name')!, 'Geändert');
    await settle(harness);
    expect(q(el, 'templates-dirty')).not.toBeNull();
    expect(harness.routeDebugElement?.componentInstance.hasUnsavedChanges()).toBe(true);

    confirm.mockResolvedValueOnce(false);
    q<HTMLButtonElement>(el, 'templates-new')!.click();
    await settle(harness);
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(q<HTMLInputElement>(el, 'templates-name')?.value).toBe('Geändert');

    q<HTMLButtonElement>(el, 'templates-new')!.click();
    await settle(harness);
    expect(q<HTMLInputElement>(el, 'templates-name')?.value).toBe('');
    expect(q(el, 'templates-dirty')).toBeNull();
  });
});
