import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WORKFLOW_API_BASE_URL } from './workflow.config';
import { WorkflowTemplateManagerComponent } from './workflow-template-manager.component';

describe('WorkflowTemplateManagerComponent', () => {
  let fixture: ComponentFixture<WorkflowTemplateManagerComponent>;
  let component: WorkflowTemplateManagerComponent;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [WorkflowTemplateManagerComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: '' },
      ],
    });
    fixture = TestBed.createComponent(WorkflowTemplateManagerComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);

    fixture.detectChanges();
    httpMock.expectOne('/templates').flush({ templates: [] });
  });

  afterEach(() => httpMock.verify());

  it('saves a template and reloads the list', () => {
    component.idText.set('welcome');
    component.nameText.set('Willkommen');
    component.subjectText.set('Hallo {{name}}');
    component.bodyText.set('<p>Hallo {{name}}</p>');

    component.save();

    const saveReq = httpMock.expectOne('/templates/welcome');
    expect(saveReq.request.method).toBe('POST');
    expect(saveReq.request.body).toEqual({
      name: 'Willkommen',
      subject: 'Hallo {{name}}',
      body: '<p>Hallo {{name}}</p>',
    });
    saveReq.flush({ id: 'welcome' });

    httpMock.expectOne('/templates').flush({ templates: [{ id: 'welcome', name: 'Willkommen' }] });
    expect(component.message()).toContain('welcome');
  });

  it('loads a template into the form', () => {
    component.select('welcome');
    httpMock.expectOne('/templates/welcome').flush({
      id: 'welcome',
      name: 'Willkommen',
      subject: 'Hallo {{name}}',
      body: '<p>Hi</p>',
    });

    expect(component.nameText()).toBe('Willkommen');
    expect(component.subjectText()).toBe('Hallo {{name}}');
    expect(component.bodyText()).toBe('<p>Hi</p>');
  });

  it('requires an id before saving', () => {
    component.idText.set('');
    component.save();
    expect(component.error()).toContain('ID');
  });
});
