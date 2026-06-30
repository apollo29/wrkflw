import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WORKFLOW_API_BASE_URL } from './workflow.config';
import { WorkflowEditorComponent } from './workflow-editor.component';

describe('WorkflowEditorComponent', () => {
  let fixture: ComponentFixture<WorkflowEditorComponent>;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [WorkflowEditorComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: '' },
      ],
    });
    fixture = TestBed.createComponent(WorkflowEditorComponent);
    httpMock = TestBed.inject(HttpTestingController);

    // ngOnInit lädt die Liste.
    fixture.detectChanges();
    httpMock.expectOne('/workflows').flush({ definitions: [] });
  });

  afterEach(() => httpMock.verify());

  it('saves a valid definition and shows the new version', () => {
    const component = fixture.componentInstance;
    component.idText.set('myflow');
    component.nameText.set('My Flow');
    component.jsonText.set('{"startStep":"a","steps":{"a":{"type":"automatic"}}}');

    component.save();

    const saveReq = httpMock.expectOne('/workflows/myflow');
    expect(saveReq.request.method).toBe('POST');
    saveReq.flush({ id: 'myflow', version: 1, active: true });

    // loadList() nach dem Speichern
    httpMock.expectOne('/workflows').flush({ definitions: [{ id: 'myflow', version: 1, name: 'My Flow', active: true }] });

    expect(component.message()).toContain('v1');
    expect(component.error()).toBeNull();
  });

  it('rejects invalid JSON locally without calling the API', () => {
    const component = fixture.componentInstance;
    component.idText.set('x');
    component.jsonText.set('{ not valid json');

    component.save();

    expect(component.error()).toContain('JSON');
    httpMock.expectNone('/workflows/x');
  });

  it('shows the engine validation error on HTTP 400', () => {
    const component = fixture.componentInstance;
    component.idText.set('broken');
    component.jsonText.set('{"startStep":"a","steps":{"a":{"type":"automatic","transitions":[{"to":"ghost"}]}}}');

    component.save();

    httpMock.expectOne('/workflows/broken').flush(
      { error: { code: 'invalid_definition', message: "unbekanntes Ziel 'ghost'" } },
      { status: 400, statusText: 'Bad Request' },
    );

    expect(component.error()).toContain('ghost');
  });
});
