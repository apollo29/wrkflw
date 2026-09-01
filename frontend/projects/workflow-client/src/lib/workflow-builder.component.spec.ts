import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WORKFLOW_API_BASE_URL } from './workflow.config';
import { WorkflowBuilderComponent } from './workflow-builder.component';

describe('WorkflowBuilderComponent', () => {
  let fixture: ComponentFixture<WorkflowBuilderComponent>;
  let component: WorkflowBuilderComponent;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [WorkflowBuilderComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: '' },
      ],
    });
    fixture = TestBed.createComponent(WorkflowBuilderComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);

    fixture.detectChanges();
    httpMock.expectOne('/workflows').flush({ definitions: [] });
    httpMock.expectOne('/actions').flush({
      actions: [
        {
          key: 'send_email',
          config: [
            { name: 'to', label: 'An', type: 'text' },
            { name: 'subject', label: 'Betreff', type: 'text' },
            { name: 'body', label: 'Text', type: 'textarea' },
          ],
        },
      ],
    });
    httpMock.expectOne('/upload-handlers').flush({ handlers: [] });
    httpMock.expectOne('/templates').flush({ templates: [] });
    httpMock.expectOne('/data-catalog').flush({
      entities: [{ entity: 'order', label: 'Bestellung', fields: ['id', 'status', 'total'] }],
    });
  });

  afterEach(() => httpMock.verify());

  it('builds a definition and saves it', () => {
    component.newDefinition();
    component.model.set({ ...component.model(), id: 'flow', name: 'Mein Flow' });
    component.addStep('automatic');

    component.save();

    const req = httpMock.expectOne('/workflows/flow');
    expect(req.request.method).toBe('POST');
    expect(req.request.body.name).toBe('Mein Flow');
    expect(req.request.body.status).toBe('active');
    const steps = req.request.body.definition.steps as Record<string, unknown>;
    expect(Object.keys(steps).length).toBe(1);
    req.flush({ id: 'flow', version: 1, active: true, status: 'active' });

    httpMock.expectOne('/workflows').flush({ definitions: [] });
    expect(component.message()).toContain('v1');
    expect(component.error()).toBeNull();
  });

  it('saves as a draft with the chosen status', () => {
    component.newDefinition();
    component.model.set({ ...component.model(), id: 'flow', name: 'Flow' });
    component.addStep('automatic');
    component.status.set('draft');

    component.save();

    const req = httpMock.expectOne('/workflows/flow');
    expect(req.request.body.status).toBe('draft');
    req.flush({ id: 'flow', version: 1, active: false, status: 'draft' });
    httpMock.expectOne('/workflows').flush({ definitions: [] });
    expect(component.message()).toContain('Entwurf');
  });

  it('adopts the status of the loaded definition', () => {
    component.definitions.set([
      { id: 'flow', version: 3, name: 'Flow', active: true, status: 'draft', instances: 0, runningInstances: 0 },
    ]);

    component.loadDefinition('flow');
    httpMock.expectOne('/workflows/flow').flush({ id: 'flow', definition: { id: 'flow', startStep: '', steps: {} } });

    expect(component.status()).toBe('draft');
  });

  /**
   * Kopfleiste: ID, Name und Version stehen nebeneinander.
   *
   * Die Version stand vorher nirgends — eine geladene Definition sah aus wie
   * ein frischer Entwurf, und die Nummer tauchte erst in der Meldung nach dem
   * Speichern auf.
   */
  it('shows the version of the loaded definition in the header', () => {
    component.loadDefinition('flow');
    httpMock.expectOne('/workflows/flow').flush({
      id: 'flow',
      definition: { id: 'flow', version: 4, startStep: '', steps: {} },
    });
    fixture.detectChanges();

    expect(component.loadedVersion()).toBe(4);
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.wfb__bar-version')?.textContent?.trim(),
    ).toBe('v4');
  });

  it('marks an unsaved draft as new instead of showing a version', () => {
    component.newDefinition();
    fixture.detectChanges();

    expect(component.loadedVersion()).toBeNull();
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.wfb__bar-version')?.textContent?.trim(),
    ).toBe('neu');
  });

  /** Nach dem Speichern gilt die neue Fassung, ohne dass man neu laden muss. */
  it('takes over the version returned by save()', () => {
    component.model.set({ ...component.model(), id: 'flow', startStep: 'a' });
    component.save();

    httpMock.expectOne('/workflows/flow').flush({ id: 'flow', version: 7, active: true, status: 'active' });
    httpMock.expectOne('/workflows').flush({ definitions: [] });

    expect(component.loadedVersion()).toBe(7);
  });

  it('shows the JSON view of the current model', () => {
    component.model.set({ ...component.model(), id: 'flow', startStep: 'a' });
    component.showJson();

    expect(component.viewMode()).toBe('json');
    expect(component.jsonText()).toContain('"startStep"');
  });

  it('reports a server validation error', () => {
    component.model.set({ ...component.model(), id: 'broken' });
    component.save();

    httpMock.expectOne('/workflows/broken').flush(
      { error: { code: 'invalid_definition', message: "unbekanntes Ziel 'ghost'" } },
      { status: 400, statusText: 'Bad Request' },
    );

    expect(component.error()).toContain('ghost');
  });

  it('lazily loads a template for the inline preview', () => {
    expect(component.templatePreview('welcome')).toBeNull();

    httpMock.expectOne('/templates/welcome').flush({
      id: 'welcome',
      name: 'Willkommen',
      subject: 'Hallo',
      body: '<p>Hi</p>',
    });

    const cached = component.templatePreview('welcome');
    expect(cached?.subject).toBe('Hallo');

    // Zweiter Zugriff löst keinen weiteren Request aus (Cache).
    httpMock.expectNone('/templates/welcome');
  });

  it('returns null for an empty template id without a request', () => {
    expect(component.templatePreview('')).toBeNull();
    httpMock.expectNone('/templates/');
  });

  /**
   * Das Archiv trennt zwei Fragen, die man leicht in eine wirft: was ist noch
   * in Gebrauch, und was darf weg?
   */
  describe('Archiv', () => {
    /** Baut eine Zeile der Uebersicht. */
    function zeile(id: string, version: number, instances = 0, runningInstances = 0) {
      return { id, version, name: id, active: false, status: 'active' as const, instances, runningInstances };
    }

    it('haelt nur die neueste Version in der Hauptliste', () => {
      component.definitions.set([zeile('flow', 1), zeile('flow', 2), zeile('flow', 3)]);

      expect(component.aktuelleDefinitionen().map((d) => d.version)).toEqual([3]);
      expect(component.archivierteDefinitionen().map((d) => d.version)).toEqual([2, 1]);
    });

    it('laesst eine alte Version mit laufendem Durchlauf oben stehen', () => {
      // Sie ist nicht mehr die aktuelle, aber in Gebrauch — im Archiv waere
      // sie am falschen Ort.
      component.definitions.set([zeile('flow', 1, 5, 2), zeile('flow', 2)]);

      expect(component.aktuelleDefinitionen().map((d) => d.version)).toEqual([1, 2]);
      expect(component.archivierteDefinitionen()).toEqual([]);
    });

    it('sperrt das Loeschen, solange ein abgeschlossener Durchlauf verweist', () => {
      const mitVerlauf = zeile('flow', 1, 3, 0);
      const ohne = zeile('flow', 2, 0, 0);

      expect(component.loeschsperre(mitVerlauf)).toContain('3 abgeschlossene');
      expect(component.loeschsperre(ohne)).toBe('');
    });

    it('formuliert den einen Durchlauf im Singular', () => {
      expect(component.loeschsperre(zeile('flow', 1, 1, 0))).toContain('Ein abgeschlossener');
    });

    it('loescht eine freie Version und laedt die Liste neu', () => {
      component.definitions.set([zeile('flow', 1), zeile('flow', 2)]);

      component.deleteVersion(zeile('flow', 1));

      const req = httpMock.expectOne('/workflows/flow/versions/1');
      expect(req.request.method).toBe('DELETE');
      req.flush(null, { status: 204, statusText: 'No Content' });
      httpMock.expectOne('/workflows').flush({ definitions: [] });

      expect(component.message()).toContain('v1');
    });

    it('schickt gar keine Anfrage, wenn die Version gesperrt ist', () => {
      // Die Sperre steht im Knopf — aber sie muss auch dann halten, wenn
      // jemand die Methode direkt aufruft. httpMock.verify() im afterEach
      // meldet eine Anfrage, die trotzdem hinausginge.
      component.deleteVersion(zeile('flow', 1, 2, 0));

      expect(component.message()).toBeNull();
    });
  });

  it('lists distinct workflow options for the workflow-ref field', () => {
    component.definitions.set([
      { id: 'a', version: 1, name: 'Alpha alt', active: false, status: 'active', instances: 0, runningInstances: 0 },
      { id: 'a', version: 2, name: 'Alpha', active: true, status: 'active', instances: 0, runningInstances: 0 },
      { id: 'b', version: 1, name: 'Beta', active: true, status: 'active', instances: 0, runningInstances: 0 },
    ]);

    const options = component.workflowOptions();
    expect(options).toEqual([
      { id: 'a', name: 'Alpha' },
      { id: 'b', name: 'Beta' },
    ]);
  });

  it('hides subject/body once an email template is selected', () => {
    component.addStep('automatic');
    const step = component.model().steps[0];
    const subject = { name: 'subject', label: 'Betreff', type: 'text' };
    const to = { name: 'to', label: 'An', type: 'text' };

    expect(component.isFieldHiddenByTemplate(step, subject)).toBeFalse();

    component.setConfig(step, 'templateId', 'welcome');
    expect(component.isFieldHiddenByTemplate(step, subject)).toBeTrue();
    expect(component.isFieldHiddenByTemplate(step, { name: 'body', label: 'Inhalt', type: 'html' })).toBeTrue();
    // Andere Felder (z. B. Empfänger) bleiben sichtbar.
    expect(component.isFieldHiddenByTemplate(step, to)).toBeFalse();
  });

  it('adds a data-check step and lists fields of the chosen entity', () => {
    component.newDefinition();
    component.addDataCheckStep();
    const step = component.model().steps[0];

    expect(component.isDataCheckStep(step)).toBeTrue();
    expect(component.stepKind(step)).toBe('datacheck');
    expect(step.action).toBe('check_data');

    // Ohne gewählte Tabelle keine Felder; nach Auswahl die Katalog-Felder.
    expect(component.entityFields(step)).toEqual([]);
    component.setConfig(step, 'entity', 'order');
    expect(component.entityFields(step)).toEqual(['id', 'status', 'total']);
  });

  it('reads and writes a boolean config value', () => {
    component.addStep('automatic');
    const step = component.model().steps[0];
    expect(component.configBool(step, 'waitForCompletion')).toBeFalse();

    component.setConfigBool(step, 'waitForCompletion', true);
    expect(component.configBool(step, 'waitForCompletion')).toBeTrue();
    expect(step.config['waitForCompletion']).toBe(true);
  });
});
