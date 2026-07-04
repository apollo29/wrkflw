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
    const steps = req.request.body.definition.steps as Record<string, unknown>;
    expect(Object.keys(steps).length).toBe(1);
    req.flush({ id: 'flow', version: 1, active: true });

    httpMock.expectOne('/workflows').flush({ definitions: [] });
    expect(component.message()).toContain('v1');
    expect(component.error()).toBeNull();
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
});
