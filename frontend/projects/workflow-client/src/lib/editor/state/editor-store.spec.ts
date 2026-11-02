import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideWorkflowAdmin } from '../../config/config';
import { WfToastService } from '../../ui/toast';
import { EditorStore, VALIDATE_DEBOUNCE_MS } from './editor-store';

const DEFINITION = {
  id: 'antrag',
  name: 'Antrag',
  startStep: 'erfassen',
  steps: {
    erfassen: {
      type: 'interactive',
      ui: { title: 'Angaben', fields: [] },
      transitions: [{ to: 'ende', event: 'submit' }],
    },
    ende: { type: 'automatic', action: 'send_email', transitions: [] },
  },
};

describe('EditorStore', () => {
  let store: EditorStore;
  let http: HttpTestingController;

  beforeEach(() => {
    vi.useFakeTimers();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideWorkflowAdmin({ apiBaseUrl: '/api' }),
        EditorStore,
      ],
    });
    store = TestBed.inject(EditorStore);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => vi.useRealTimers());

  /** Kataloge beantworten (leer, bis auf die E-Mail-Aktion). */
  function answerCatalogs(): void {
    http
      .expectOne('/api/actions')
      .flush({ actions: [{ key: 'send_email', label: 'E-Mail senden', fields: [] }] });
    http
      .expectOne((r) => r.url === '/api/data-models' && !r.params.has('writable'))
      .flush({ models: [] });
    http
      .expectOne((r) => r.url === '/api/data-models' && r.params.has('writable'))
      .flush({ models: [] });
    http.expectOne('/api/upload-handlers').flush({ handlers: [] });
    http.expectOne((r) => r.url === '/api/templates').flush({ templates: [] });
    http.expectOne('/api/workflows').flush({ definitions: [] });
  }

  async function load(): Promise<void> {
    const done = store.load('antrag');
    http.expectOne('/api/workflows/antrag').flush({
      id: 'antrag',
      version: 3,
      status: 'active',
      readOnly: false,
      updatedAt: '',
      definition: DEFINITION,
    });
    answerCatalogs();
    await done;
  }

  it('loads, selects the first step and is clean (E-06)', async () => {
    await load();
    expect(store.version()).toBe(3);
    expect(store.selected()).toBe('erfassen');
    expect(store.dirty()).toBe(false);
    expect(store.issues()).toEqual([]);
  });

  it('becomes dirty on change and clean again when changed back', async () => {
    await load();
    store.update((m) => ({ ...m, name: 'Neu' }));
    expect(store.dirty()).toBe(true);
    store.update((m) => ({ ...m, name: 'Antrag' }));
    expect(store.dirty()).toBe(false);
    store.setStatus('draft');
    expect(store.dirty()).toBe(true);
  });

  it('validates at the server after a pause and shows errors at the step', async () => {
    await load();
    store.update((m) => ({ ...m, name: 'x' }));
    TestBed.tick();
    vi.advanceTimersByTime(VALIDATE_DEBOUNCE_MS);
    const req = http.expectOne('/api/workflows/validate');
    expect((req.request.body as { definition: { id: string } }).definition.id).toBe('antrag');
    req.flush({
      valid: false,
      errors: [
        { code: 'no_exit', message: 'Kein Ende erreichbar.', step: 'ende', transition: null },
      ],
    });
    expect(store.stepsWithErrors().has('ende')).toBe(true);
    expect(store.canSave()).toBe(false);
    store.setStatus('draft');
    expect(store.canSave()).toBe(true);
  });

  it('saves and reports the new version (E-06)', async () => {
    await load();
    store.update((m) => ({ ...m, name: 'Antrag 2' }));
    const saved = store.save();
    const req = http.expectOne('/api/workflows/antrag');
    expect(req.request.body).toMatchObject({ name: 'Antrag 2', status: 'active' });
    req.flush({ id: 'antrag', version: 4, status: 'active', newVersion: true, validation: [] });
    expect(await saved).toBe(true);
    expect(store.version()).toBe(4);
    expect(store.dirty()).toBe(false);
    expect(store.message()).toEqual({ tone: 'ok', text: 'Gespeichert: antrag v4 (aktiv).' });
  });

  it('blocks saving as active with errors, but saves a draft (Rückfrage Phase 7)', async () => {
    await load();
    store.update((m) => ({ ...m, startStep: 'gibt-es-nicht' }));
    expect(await store.save()).toBe(false);
    http.expectNone('/api/workflows/antrag');

    store.setStatus('draft');
    const saved = store.save();
    http
      .expectOne('/api/workflows/antrag')
      .flush({ id: 'antrag', version: 3, status: 'draft', newVersion: false, validation: [] });
    expect(await saved).toBe(true);
  });

  it('maps server validation errors to steps', async () => {
    await load();
    store.update((m) => ({ ...m, name: 'y' }));
    const saved = store.save();
    http.expectOne('/api/workflows/antrag').flush(
      {
        error: {
          code: 'invalid_definition',
          message: 'x',
          details: {
            errors: [
              {
                code: 'unreachable',
                message: 'Nicht erreichbar.',
                step: 'ende',
                transition: null,
              },
            ],
          },
        },
      },
      { status: 422, statusText: 'Unprocessable' },
    );
    expect(await saved).toBe(false);
    expect(store.message()).toEqual({ tone: 'error', text: 'Nicht erreichbar.' });
    expect(store.stepsWithErrors().has('ende')).toBe(true);
  });

  it('asks for an id before saving a new workflow', async () => {
    const ready = store.newWorkflow();
    answerCatalogs();
    await ready;
    store.add('interactive');
    expect(await store.save()).toBe(false);
    expect(store.message()?.text).toBe('Bitte eine ID für den Workflow angeben.');
  });

  it('switches to JSON and back; invalid JSON stays with a message (E-35, E-36)', async () => {
    await load();
    store.setView('json');
    expect(JSON.parse(store.jsonText())).toMatchObject({ id: 'antrag', startStep: 'erfassen' });
    store.setView('json'); // Klick auf die aktive Ansicht tut nichts.
    expect(store.view()).toBe('json');

    store.jsonText.set('{ kaputt');
    store.setView('visual');
    expect(store.view()).toBe('json');
    expect(store.message()?.text).toBe('Ungültiges JSON – bitte korrigieren.');

    store.jsonText.set(JSON.stringify({ ...DEFINITION, name: 'Aus JSON' }));
    store.setView('visual');
    expect(store.view()).toBe('visual');
    expect(store.model().name).toBe('Aus JSON');
  });

  it('saves the JSON text in JSON view (E-37)', async () => {
    await load();
    store.setView('json');
    store.jsonText.set(JSON.stringify({ ...DEFINITION, name: 'Text' }));
    const saved = store.save();
    const req = http.expectOne('/api/workflows/antrag');
    expect((req.request.body as { definition: { name: string } }).definition.name).toBe('Text');
    req.flush({ id: 'antrag', version: 4, status: 'active', newVersion: true, validation: [] });
    expect(await saved).toBe(true);
  });

  it('deletes with undo (9.14)', async () => {
    await load();
    store.remove('ende');
    expect(store.steps().map((s) => s.name)).toEqual(['erfassen']);
    const toasts = TestBed.inject(WfToastService);
    const toast = toasts.toasts()[0]!;
    expect(toast.message).toBe('Schritt «ende» gelöscht.');
    toasts.act(toast.id);
    expect(store.steps().map((s) => s.name)).toEqual(['erfassen', 'ende']);
  });

  it('renames with references and reports duplicates', async () => {
    await load();
    expect(store.rename('ende', 'erfassen')).toBe('duplicate');
    store.select('ende');
    expect(store.rename('ende', 'abschluss')).toBeNull();
    expect(store.selected()).toBe('abschluss');
    expect(store.steps()[0]?.transitions[0]?.to).toBe('abschluss');
  });

  it('loads an old version read-only', async () => {
    const done = store.load('antrag', 2);
    http.expectOne('/api/workflows/antrag/versions/2').flush({
      id: 'antrag',
      version: 2,
      status: 'inactive',
      readOnly: true,
      updatedAt: '',
      definition: DEFINITION,
    });
    answerCatalogs();
    await done;
    expect(store.readOnly()).toBe(true);
    store.update((m) => ({ ...m, name: 'nein' }));
    expect(store.model().name).toBe('Antrag');
    expect(await store.save()).toBe(false);
  });
});
