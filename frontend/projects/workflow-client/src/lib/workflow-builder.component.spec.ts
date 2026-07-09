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
    httpMock.expectOne('/templates').flush({ templates: [] });
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
      { id: 'flow', version: 3, name: 'Flow', active: true, status: 'draft' },
    ]);

    component.loadDefinition('flow');
    httpMock.expectOne('/workflows/flow').flush({ id: 'flow', definition: { id: 'flow', startStep: '', steps: {} } });

    expect(component.status()).toBe('draft');
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

  it('lists distinct workflow options for the workflow-ref field', () => {
    component.definitions.set([
      { id: 'a', version: 1, name: 'Alpha alt', active: false, status: 'active' },
      { id: 'a', version: 2, name: 'Alpha', active: true, status: 'active' },
      { id: 'b', version: 1, name: 'Beta', active: true, status: 'active' },
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

  it('reads and writes a boolean config value', () => {
    component.addStep('automatic');
    const step = component.model().steps[0];
    expect(component.configBool(step, 'waitForCompletion')).toBeFalse();

    component.setConfigBool(step, 'waitForCompletion', true);
    expect(component.configBool(step, 'waitForCompletion')).toBeTrue();
    expect(step.config['waitForCompletion']).toBe(true);
  });
});
