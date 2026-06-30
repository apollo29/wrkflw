import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WORKFLOW_API_BASE_URL } from './workflow.config';
import { WorkflowRunnerComponent } from './workflow-runner.component';

describe('WorkflowRunnerComponent', () => {
  let fixture: ComponentFixture<WorkflowRunnerComponent>;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [WorkflowRunnerComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: '' },
      ],
    });
    fixture = TestBed.createComponent(WorkflowRunnerComponent);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('renders an interactive step, submits an event and reaches completion', () => {
    fixture.componentRef.setInput('instanceId', 'i1');
    fixture.detectChanges();

    httpMock.expectOne('/instances/i1/current-step').flush({
      instanceId: 'i1',
      status: 'waiting_event',
      step: 'await_profile',
      type: 'interactive',
      interactive: true,
      finished: false,
      ui: { title: 'Profil', fields: [{ name: 'phone', label: 'Telefon', type: 'text' }] },
      events: ['submit'],
      context: {},
    });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('Profil');

    const component = fixture.componentInstance;
    component.setValue('acceptedTerms', true);
    component.submit('submit');

    const eventReq = httpMock.expectOne('/instances/i1/events');
    expect(eventReq.request.body).toEqual({ event: 'submit', payload: { acceptedTerms: true } });
    eventReq.flush({ id: 'i1', status: 'running', currentStep: 'check_vip' });

    httpMock.expectOne('/instances/i1/current-step').flush({
      instanceId: 'i1',
      status: 'completed',
      step: 'done',
      type: 'automatic',
      interactive: false,
      finished: true,
      ui: {},
      events: [],
      context: {},
    });
    fixture.detectChanges();
    expect(fixture.nativeElement.textContent).toContain('abgeschlossen');
  });

  it('shows an error state when the request fails', () => {
    fixture.componentRef.setInput('instanceId', 'bad');
    fixture.detectChanges();

    httpMock
      .expectOne('/instances/bad/current-step')
      .flush('nope', { status: 404, statusText: 'Not Found' });
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Fehler');
  });
});
