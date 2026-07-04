import { provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { authInterceptor } from './auth.interceptor';
import { WORKFLOW_API_BASE_URL, WORKFLOW_API_KEY } from './workflow.config';
import { WorkflowService } from './workflow.service';

const BASE = 'http://api.test';

describe('WorkflowService', () => {
  let service: WorkflowService;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: BASE },
      ],
    });
    service = TestBed.inject(WorkflowService);
    httpMock = TestBed.inject(HttpTestingController);
  });

  afterEach(() => httpMock.verify());

  it('start() posts context and subject and returns the summary', () => {
    let result: unknown;
    service.start('onboarding', { name: 'Mara' }, { type: 'user', id: '7' }).subscribe((r) => (result = r));

    const req = httpMock.expectOne(`${BASE}/workflows/onboarding/instances`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body.context).toEqual({ name: 'Mara' });
    expect(req.request.body.subjectType).toBe('user');
    expect(req.request.body.subjectId).toBe('7');
    req.flush({ id: 'i1', status: 'waiting_event', currentStep: 'await_profile' });

    expect(result).toEqual({ id: 'i1', status: 'waiting_event', currentStep: 'await_profile' });
  });

  it('currentStep() requests the current-step endpoint', () => {
    service.currentStep('i1').subscribe();

    const req = httpMock.expectOne(`${BASE}/instances/i1/current-step`);
    expect(req.request.method).toBe('GET');
    req.flush({
      instanceId: 'i1',
      status: 'waiting_event',
      step: 'await_profile',
      type: 'interactive',
      interactive: true,
      finished: false,
      ui: {},
      events: ['submit'],
      context: {},
    });
  });

  it('sendEvent() posts event and payload', () => {
    service.sendEvent('i1', 'submit', { acceptedTerms: true }).subscribe();

    const req = httpMock.expectOne(`${BASE}/instances/i1/events`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ event: 'submit', payload: { acceptedTerms: true } });
    req.flush({ id: 'i1', status: 'running', currentStep: 'check_vip' });
  });

  it('listDefinitions() and getDefinition() use GET', () => {
    service.listDefinitions().subscribe();
    const listReq = httpMock.expectOne(`${BASE}/workflows`);
    expect(listReq.request.method).toBe('GET');
    listReq.flush({ definitions: [] });

    service.getDefinition('onboarding').subscribe();
    const getReq = httpMock.expectOne(`${BASE}/workflows/onboarding`);
    expect(getReq.request.method).toBe('GET');
    getReq.flush({ id: 'onboarding', definition: { startStep: 'a', steps: {} } });
  });

  it('saveDefinition() posts name and definition', () => {
    const definition = { startStep: 'a', steps: { a: { type: 'automatic' } } };
    service.saveDefinition('onboarding', 'Onboarding', definition).subscribe();

    const req = httpMock.expectOne(`${BASE}/workflows/onboarding`);
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ name: 'Onboarding', definition });
    req.flush({ id: 'onboarding', version: 2, active: true });
  });

  it('listActions() requests the action catalog', () => {
    service.listActions().subscribe();
    const req = httpMock.expectOne(`${BASE}/actions`);
    expect(req.request.method).toBe('GET');
    req.flush({ actions: [] });
  });

  it('lists, gets and saves templates', () => {
    service.listTemplates().subscribe();
    const listReq = httpMock.expectOne(`${BASE}/templates`);
    expect(listReq.request.method).toBe('GET');
    listReq.flush({ templates: [] });

    service.getTemplate('welcome').subscribe();
    const getReq = httpMock.expectOne(`${BASE}/templates/welcome`);
    expect(getReq.request.method).toBe('GET');
    getReq.flush({ id: 'welcome', name: 'W', subject: 'S', body: 'B' });

    service.saveTemplate('welcome', 'W', 'Hallo {{name}}', '<p>Hi</p>').subscribe();
    const saveReq = httpMock.expectOne(`${BASE}/templates/welcome`);
    expect(saveReq.request.method).toBe('POST');
    expect(saveReq.request.body).toEqual({ name: 'W', subject: 'Hallo {{name}}', body: '<p>Hi</p>' });
    saveReq.flush({ id: 'welcome' });
  });

  it('getInstance() and history() use GET', () => {
    service.getInstance('i1').subscribe();
    const stateReq = httpMock.expectOne(`${BASE}/instances/i1`);
    expect(stateReq.request.method).toBe('GET');
    stateReq.flush({
      id: 'i1',
      status: 'completed',
      currentStep: 'done',
      context: {},
      lastError: null,
    });

    service.history('i1').subscribe();
    const historyReq = httpMock.expectOne(`${BASE}/instances/i1/history`);
    expect(historyReq.request.method).toBe('GET');
    historyReq.flush({ instanceId: 'i1', history: [] });
  });
});

describe('authInterceptor', () => {
  function setup(apiKey: string | null): { service: WorkflowService; httpMock: HttpTestingController } {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: BASE },
        { provide: WORKFLOW_API_KEY, useValue: apiKey },
      ],
    });
    return {
      service: TestBed.inject(WorkflowService),
      httpMock: TestBed.inject(HttpTestingController),
    };
  }

  it('adds a Bearer header when an API key is configured', () => {
    const { service, httpMock } = setup('secret');
    service.currentStep('i1').subscribe();

    const req = httpMock.expectOne(`${BASE}/instances/i1/current-step`);
    expect(req.request.headers.get('Authorization')).toBe('Bearer secret');
    req.flush({});
    httpMock.verify();
  });

  it('omits the header when no API key is configured', () => {
    const { service, httpMock } = setup(null);
    service.currentStep('i1').subscribe();

    const req = httpMock.expectOne(`${BASE}/instances/i1/current-step`);
    expect(req.request.headers.has('Authorization')).toBe(false);
    req.flush({});
    httpMock.verify();
  });
});
