import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
  TestRequest,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { provideWorkflowAdmin } from '../config/config';
import { WfDialogService } from '../ui/dialog';
import { WfDataSourcesPage } from './data-sources-page';
import { auditText } from './source-parts';
import { WfI18n } from '../i18n/i18n';

const CONNECTION = {
  id: 'antrag-api',
  name: 'Antrags-API',
  baseUrl: 'https://api.example.org',
  authType: 'bearer',
  authHeader: null,
  secretStored: true,
};

const MODEL = {
  name: 'antrag',
  label: 'Anträge',
  connection: 'antrag-api',
  idField: 'id',
  listPath: 'data.items',
  subset: { archiviert: 'false' },
  operations: { load: { method: 'GET', path: '/a/{id}' }, find: { method: 'GET', path: '/a' } },
  fields: [
    { name: 'id', label: 'ID', type: 'text', path: 'data.id', writable: false },
    { name: 'status', label: 'Status', type: 'text', path: 'data.status', writable: false },
    { name: 'betrag', label: 'Betrag', type: 'number', path: 'data.betrag', writable: false },
  ],
};

const TRIGGER = {
  id: 'offen',
  name: 'Offene',
  model: 'antrag',
  conditions: [{ field: 'status', operator: '=', value: 'offen' }],
  definitionId: 'antrag-pruefung',
  inputs: { antrag_id: 'id' },
  intervalMinutes: 15,
  lockDays: 7,
  active: false,
};

describe('WfDataSourcesPage', () => {
  let http: HttpTestingController;
  let confirm: ReturnType<typeof vi.fn>;
  let harness: RouterTestingHarness;
  let el: HTMLElement;

  async function setup(url: string): Promise<void> {
    confirm = vi.fn().mockResolvedValue(true);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideWorkflowAdmin({ apiBaseUrl: '/api' }),
        provideRouter([{ path: 'ds', component: WfDataSourcesPage }]),
        { provide: WfDialogService, useValue: { confirm } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    harness = await RouterTestingHarness.create(url);
    el = harness.routeNativeElement as HTMLElement;
    await settle();
  }

  async function settle(): Promise<void> {
    for (let i = 0; i < 4; i++) {
      await harness.fixture.whenStable();
      harness.detectChanges();
    }
  }

  /** Beantwortet alle offenen Anfragen an diese Adresse (Methode optional). */
  async function reply(
    url: string,
    body: unknown,
    method = 'GET',
    status = 200,
  ): Promise<TestRequest[]> {
    const reqs = http.match((r) => r.url === url && r.method === method);
    expect(reqs.length, `${method} ${url}`).toBeGreaterThan(0);
    reqs.forEach((r) => r.flush(body as object, { status, statusText: 'x' }));
    await settle();
    return reqs;
  }

  async function fail(
    url: string,
    method: string,
    status: number,
    error: unknown,
  ): Promise<TestRequest> {
    const req = http.expectOne((r) => r.url === url && r.method === method);
    req.flush({ error }, { status, statusText: 'x' });
    await settle();
    return req;
  }

  const q = <T extends HTMLElement>(id: string): T | null =>
    el.querySelector<T>(`[data-testid="${id}"]`);
  async function type(id: string, value: string): Promise<void> {
    const input = q<HTMLInputElement>(id)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    await settle();
  }
  async function choose(id: string, value: string): Promise<void> {
    const select = q<HTMLSelectElement>(id)!;
    select.value = value;
    select.dispatchEvent(new Event('change'));
    await settle();
  }
  async function click(id: string): Promise<void> {
    q<HTMLButtonElement>(id)!.click();
    await settle();
  }

  describe('connections', () => {
    it('creates a connection with a secret that never shows up again', async () => {
      await setup('/ds');
      await reply('/api/data-sources/connections', { connections: [] });
      expect(el.textContent).toContain('Noch keine Verbindungen.');
      await click('ds-new');
      await type('conn-id', 'neu');
      await type('conn-name', 'Neu');
      await type('conn-url', 'http://intern');
      await choose('conn-auth', 'bearer');
      await type('conn-secret', 'geheim-123');
      await click('ds-save');
      // Prüfung im Client: kein Aufruf ohne https.
      expect(http.match('/api/data-sources/connections').length).toBe(0);
      expect(el.textContent).toContain('Die Adresse muss mit https:// beginnen.');

      await type('conn-url', 'https://api.example.org');
      expect(q('ds-dirty')).not.toBeNull();
      await click('ds-save');
      const [post] = await reply(
        '/api/data-sources/connections',
        { ...CONNECTION, id: 'neu', name: 'Neu' },
        'POST',
        201,
      );
      expect(post!.request.body).toEqual({
        id: 'neu',
        name: 'Neu',
        baseUrl: 'https://api.example.org',
        authType: 'bearer',
        secret: 'geheim-123',
      });
      await reply('/api/data-sources/connections', {
        connections: [{ ...CONNECTION, id: 'neu', name: 'Neu' }],
      });
      expect(TestBed.inject(Router).url).toContain('id=neu');
      await reply('/api/data-sources/connections/neu', { ...CONNECTION, id: 'neu', name: 'Neu' });
      await reply('/api/data-sources/connections/neu/usage', { id: 'neu', models: [] });
      expect(q('conn-stored')?.textContent).toContain('Hinterlegt');
      expect(q('conn-secret')).toBeNull();
      expect(el.innerHTML).not.toContain('geheim-123');
      expect(q('ds-dirty')).toBeNull();
      expect(q('ds-message')?.textContent).toContain('Gespeichert: neu.');
    });

    it('replaces the secret, tests the connection and locks delete while used', async () => {
      await setup('/ds?bereich=verbindungen&id=antrag-api');
      await reply('/api/data-sources/connections', { connections: [CONNECTION] });
      await reply('/api/data-sources/connections/antrag-api', CONNECTION);
      await reply('/api/data-sources/connections/antrag-api/usage', {
        id: 'antrag-api',
        models: ['antrag'],
      });
      await reply('/api/data-sources/audit', {
        entries: [
          {
            at: new Date().toISOString(),
            actor: { id: 'a', name: 'Anna' },
            action: 'connection.updated',
            objectType: 'connection',
            objectId: 'antrag-api',
            detail: { changed: ['baseUrl'] },
          },
        ],
      });
      expect(q('ds-audit')?.textContent).toContain('Geändert von Anna · gerade eben: baseUrl');
      expect(q<HTMLButtonElement>('ds-delete')?.disabled).toBe(true);
      expect(el.textContent).toContain('Löschen gesperrt: wird noch verwendet.');
      expect(q('ds-usage')?.textContent).toContain('antrag');

      await click('conn-replace');
      await type('conn-secret', 'neu-geheim');
      expect(q('ds-dirty')).toBeNull();
      await click('conn-replace-apply');
      const [put] = await reply(
        '/api/data-sources/connections/antrag-api/secret',
        CONNECTION,
        'PUT',
      );
      expect(put!.request.body).toEqual({ secret: 'neu-geheim' });
      expect(q('conn-secret')).toBeNull();
      expect(el.innerHTML).not.toContain('neu-geheim');
      expect(q('ds-message')?.textContent).toContain('Zugangsdaten ersetzt.');
      // Das Protokoll lädt neu.
      await reply('/api/data-sources/audit', { entries: [] });

      await click('conn-test');
      await reply(
        '/api/data-sources/connections/antrag-api/test',
        { ok: true, status: 200, durationMs: 184, message: null },
        'POST',
      );
      expect(q('conn-test-result')?.textContent).toContain('200 · 184 ms');
    });

    it('shows field errors from the engine at the field', async () => {
      await setup('/ds?id=antrag-api');
      await reply('/api/data-sources/connections', { connections: [CONNECTION] });
      await reply('/api/data-sources/connections/antrag-api', CONNECTION);
      await reply('/api/data-sources/connections/antrag-api/usage', {
        id: 'antrag-api',
        models: [],
      });
      await type('conn-url', 'https://10.0.0.1');
      await click('ds-save');
      const req = await fail('/api/data-sources/connections/antrag-api', 'PUT', 422, {
        code: 'validation_error',
        message: 'Bitte die markierten Angaben prüfen.',
        details: { fields: { baseUrl: ['Die Adresse zeigt auf ein internes Netz.'] } },
      });
      expect('secret' in (req.request.body as object)).toBe(false);
      expect(el.textContent).toContain('Die Adresse zeigt auf ein internes Netz.');
      expect(q('ds-dirty')).not.toBeNull();
    });
  });

  describe('models', () => {
    async function openModel(
      usage = {
        name: 'antrag',
        definitions: [
          { definitionId: 'antrag-pruefung', version: 3, step: 'daten_laden', fields: ['status'] },
        ],
        triggers: ['offen'],
      },
    ) {
      await setup('/ds?bereich=modelle&id=antrag');
      await reply('/api/data-sources/models', { models: [MODEL] });
      await reply('/api/data-sources/connections', { connections: [CONNECTION] });
      await reply('/api/data-sources/models/antrag', MODEL);
      await reply('/api/data-sources/models/antrag/usage', usage);
    }

    it('locks used fields and the ID field with a reason; writable needs update or create', async () => {
      await openModel();
      const remove = (name: string) =>
        el.querySelector<HTMLButtonElement>(`[data-field="${name}"] button`)!;
      expect(remove('id').disabled).toBe(true);
      expect(remove('id').title).toBe('Das ID-Feld lässt sich nicht entfernen.');
      expect(remove('status').disabled).toBe(true);
      expect(remove('status').title).toContain('antrag-pruefung v3 · daten_laden');
      expect(remove('status').getAttribute('aria-label')).toContain('gesperrt');
      expect(remove('betrag').disabled).toBe(false);
      expect(q('ds-usage')?.textContent).toContain('Verwendet in 1 Schritt(en):');
      expect(q('ds-usage')?.textContent).toContain('offen');
      expect(q<HTMLButtonElement>('ds-delete')?.disabled).toBe(true);

      const writable = el.querySelector<HTMLInputElement>(
        '[data-field="betrag"] input[type="checkbox"]',
      )!;
      expect(writable.disabled).toBe(true);
      const update = el.querySelector<HTMLInputElement>('[data-op="update"] input')!;
      update.value = '/a/{id}';
      update.dispatchEvent(new Event('input'));
      await settle();
      expect(writable.disabled).toBe(false);
    });

    it('fetches a sample without saving and takes a value as field', async () => {
      await openModel({ name: 'antrag', definitions: [], triggers: [] });
      await type('model-sample-id', 'A-1024');
      await click('model-sample');
      const [req] = await reply(
        '/api/data-sources/models/sample',
        {
          status: 200,
          tree: { data: { id: 'A-1024', status: 'offen', kontakt: { mail: 'p@x.ch' } } },
          paths: [{ path: 'data.kontakt.mail', type: 'text', sample: 'p@x.ch' }],
        },
        'POST',
      );
      expect(req!.request.body).toMatchObject({ id: 'A-1024', model: { name: 'antrag' } });
      expect(q('model-sample-result')?.textContent).toMatch(/200 · \d+ ms/);
      const status = el.querySelector<HTMLButtonElement>('[data-path="data.status"]')!;
      expect(status.disabled).toBe(true);
      expect(status.parentElement?.textContent).toContain('als Feld übernommen');
      el.querySelector<HTMLButtonElement>('[data-path="data.kontakt.mail"]')!.click();
      await settle();
      expect(el.querySelector('[data-field="mail"]')).not.toBeNull();
      expect(el.querySelector<HTMLButtonElement>('[data-path="data.kontakt.mail"]')!.disabled).toBe(
        true,
      );
      expect(q('ds-dirty')).not.toBeNull();
    });

    it('checks {id} in the client and maps engine field errors to the row', async () => {
      await openModel({ name: 'antrag', definitions: [], triggers: [] });
      const load = el.querySelector<HTMLInputElement>('[data-op="load"] input')!;
      load.value = '/a';
      load.dispatchEvent(new Event('input'));
      await settle();
      await click('ds-save');
      expect(el.textContent).toContain('Der Pfad braucht den Platzhalter {id}.');
      load.value = '/a/{id}';
      load.dispatchEvent(new Event('input'));
      await settle();
      await click('ds-save');
      await fail('/api/data-sources/models/antrag', 'PUT', 422, {
        code: 'validation_error',
        message: 'x',
        details: { fields: { 'fields.betrag': ['Das Feld wird noch verwendet in a v1 (s).'] } },
      });
      expect(el.querySelector('[data-field="betrag"]')?.textContent).toContain(
        'Das Feld wird noch verwendet',
      );
    });

    it('creates a model with 201', async () => {
      await setup('/ds?bereich=modelle');
      await reply('/api/data-sources/models', { models: [] });
      await reply('/api/data-sources/connections', { connections: [CONNECTION] });
      await click('ds-new');
      await type('model-name', 'kurs');
      await type('model-label', 'Kurse');
      await choose('model-connection', 'antrag-api');
      await click('ds-save');
      const [post] = await reply(
        '/api/data-sources/models',
        { ...MODEL, name: 'kurs', label: 'Kurse' },
        'POST',
        201,
      );
      expect(post!.request.body).toMatchObject({
        name: 'kurs',
        label: 'Kurse',
        connection: 'antrag-api',
        operations: {},
        fields: [{ name: 'id', path: 'id' }],
      });
      await reply('/api/data-sources/models', {
        models: [{ ...MODEL, name: 'kurs', label: 'Kurse' }],
      });
      await reply('/api/data-sources/connections', { connections: [CONNECTION] });
      expect(TestBed.inject(Router).url).toContain('id=kurs');
    });
  });

  describe('triggers', () => {
    const workflows = {
      definitions: [
        { id: 'antrag-pruefung', name: 'Antrag prüfen', version: 3, status: 'active' },
        { id: 'alt', name: 'Alt', version: 1, status: 'inactive' },
      ],
    };
    const version = {
      id: 'antrag-pruefung',
      version: 3,
      definition: {
        startStep: 'a',
        inputs: [
          { name: 'antrag_id', label: 'Antrag', required: true },
          { name: 'notiz', label: 'Notiz' },
        ],
        steps: { a: { type: 'automatic', transitions: [] } },
      },
    };

    async function lists(triggers: unknown[] = [TRIGGER]): Promise<void> {
      await reply('/api/data-sources/triggers', { triggers });
      const info = (name: string, find: boolean) => ({
        name,
        label: name,
        idField: 'id',
        fields: MODEL.fields.map((f) => ({
          name: f.name,
          label: f.label,
          type: f.type,
          writable: false,
        })),
        operations: { load: true, find, update: false, create: false },
      });
      await reply('/api/data-models', { models: [info('antrag', true), info('ohne', false)] });
      await reply('/api/workflows', workflows);
    }

    it('offers searchable models and active workflows, maps inputs and checks the interval', async () => {
      await setup('/ds?bereich=trigger');
      await lists([]);
      await click('ds-new');
      const models = [...q<HTMLSelectElement>('trigger-model')!.options].map((o) => o.value);
      expect(models).toEqual(['', 'antrag']);
      const flows = [...q<HTMLSelectElement>('trigger-workflow')!.options].map((o) => o.value);
      expect(flows).toEqual(['', 'antrag-pruefung']);

      await type('trigger-id', 'neu');
      await choose('trigger-model', 'antrag');
      await choose('trigger-workflow', 'antrag-pruefung');
      await reply('/api/workflows/antrag-pruefung/versions/3', version);
      const inputs = q('trigger-inputs')!;
      expect(inputs.textContent).toContain('antrag_id');
      expect(inputs.textContent).toContain('(Pflicht)');
      await click('trigger-condition-add');
      await type('trigger-every', '3');
      await click('ds-save');
      expect(el.textContent).toContain('Die Pflichtangabe braucht ein Feld.');
      expect(el.textContent).toContain('Mindestens 5 Minuten.');

      const select = el.querySelector<HTMLSelectElement>('[data-input="antrag_id"] select')!;
      select.value = 'id';
      select.dispatchEvent(new Event('change'));
      await choose('trigger-unit', 'h');
      await click('ds-save');
      const [post] = await reply(
        '/api/data-sources/triggers',
        { ...TRIGGER, id: 'neu', intervalMinutes: 180 },
        'POST',
        201,
      );
      expect(post!.request.body).toMatchObject({
        id: 'neu',
        model: 'antrag',
        definitionId: 'antrag-pruefung',
        inputs: { antrag_id: 'id' },
        intervalMinutes: 180,
        active: false,
        conditions: [{ field: 'id', operator: '=', value: '' }],
      });
    });

    it('checks now without starting anything', async () => {
      await setup('/ds?bereich=trigger&id=offen');
      await lists();
      await reply('/api/data-sources/triggers/offen', TRIGGER);
      await reply('/api/workflows/antrag-pruefung/versions/3', version);
      await click('trigger-check');
      await reply(
        '/api/data-sources/triggers/offen/check-now',
        { id: 'offen', matches: 3 },
        'POST',
      );
      expect(q('trigger-check-result')?.textContent).toContain(
        '3 Datensätze treffen aktuell zu. Gestartet wird nichts.',
      );
    });
  });

  it('asks before leaving unsaved changes for another area', async () => {
    await setup('/ds?id=antrag-api');
    await reply('/api/data-sources/connections', { connections: [CONNECTION] });
    await reply('/api/data-sources/connections/antrag-api', CONNECTION);
    await reply('/api/data-sources/connections/antrag-api/usage', { id: 'antrag-api', models: [] });
    await type('conn-name', 'Anders');
    const page = harness.routeDebugElement!.componentInstance as WfDataSourcesPage;
    expect(page.hasUnsavedChanges()).toBe(true);
    confirm.mockResolvedValueOnce(false);
    el.querySelectorAll<HTMLButtonElement>('wf-segment button')[1]!.click();
    await settle();
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(TestBed.inject(Router).url).not.toContain('modelle');
    expect(el.querySelectorAll('wf-segment button')[0]?.getAttribute('aria-checked')).toBe('true');
    el.querySelectorAll<HTMLButtonElement>('wf-segment button')[1]!.click();
    await settle();
    expect(TestBed.inject(Router).url).toContain('bereich=modelle');
  });

  it('writes audit lines in plain text', () => {
    TestBed.configureTestingModule({});
    const i18n = TestBed.inject(WfI18n);
    const now = Date.parse('2026-11-05T12:00:00Z');
    const entry = (action: string, at: string, detail = {}) => ({
      at,
      actor: { id: 'x', name: 'Ben' },
      action,
      objectType: 'connection',
      objectId: 'a',
      detail,
    });
    expect(auditText(i18n, entry('connection.secret_replaced', '2026-11-03T11:00:00Z'), now)).toBe(
      'Zugangsdaten ersetzt von Ben · vor 2 Tag(en)',
    );
    expect(auditText(i18n, entry('trigger.created', '2026-11-05T09:30:00Z'), now)).toBe(
      'Angelegt von Ben · vor 2 Std.',
    );
    expect(auditText(i18n, entry('model.checked', '2026-11-05T11:50:00Z'), now)).toBe(
      'Aktion «model.checked» von Ben · vor 10 Min.',
    );
  });
});
