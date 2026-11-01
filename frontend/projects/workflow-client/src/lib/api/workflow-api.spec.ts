import { HttpClient, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { provideWorkflowAdmin, WorkflowAdminConfig } from '../config/config';
import { WfApiError } from './api-error';
import { apiPath, WorkflowApi } from './workflow-api';

let controller: HttpTestingController | null = null;

function setup(config: Partial<WorkflowAdminConfig> = {}): {
  api: WorkflowApi;
  http: HttpTestingController;
} {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideWorkflowAdmin({ apiBaseUrl: '/api/', ...config }),
    ],
  });
  controller = TestBed.inject(HttpTestingController);
  return { api: TestBed.inject(WorkflowApi), http: controller };
}

describe('WorkflowApi', () => {
  afterEach(() => {
    controller?.verify();
    controller = null;
  });

  it('encodes path parts', () => {
    expect(apiPath('workflows', 'a b/c', 'versions', 2)).toBe('/workflows/a%20b%2Fc/versions/2');
  });

  it('starts an instance with body and trimmed base url', async () => {
    const { api, http } = setup();
    const result = firstValueFrom(
      api.instances.start('antrag pruefen', { context: { antragId: 'A-1' } }),
    );
    const req = http.expectOne('/api/workflows/antrag%20pruefen/instances');
    expect(req.request.method).toBe('POST');
    expect(req.request.body).toEqual({ context: { antragId: 'A-1' } });
    req.flush({ id: 'i-1', status: 'waiting_event', currentStep: 'erfassen' });
    expect((await result).id).toBe('i-1');
  });

  it('sends events with an idempotency key', () => {
    const { api, http } = setup();
    api.instances.sendEvent('i-1', 'weiter', { betrag: 3 }, 'klick-1').subscribe();
    const req = http.expectOne('/api/instances/i-1/events');
    expect(req.request.body).toEqual({ event: 'weiter', payload: { betrag: 3 } });
    expect(req.request.headers.get('Idempotency-Key')).toBe('klick-1');
    req.flush({});
  });

  it('builds query parameters and skips empty ones', () => {
    const { api, http } = setup();
    api.instances.list({ status: 'failed', definition: '', limit: 20 }).subscribe();
    const req = http.expectOne((r) => r.url === '/api/instances');
    expect(req.request.params.keys()).toEqual(['status', 'limit']);
    expect(req.request.params.get('limit')).toBe('20');
    req.flush({ instances: [], counts: {} });

    api.catalogs.dataModels(true).subscribe();
    http
      .expectOne((r) => r.url === '/api/data-models' && r.params.get('writable') === '1')
      .flush({ models: [] });
  });

  it('sends the API key only to its own requests', () => {
    const { api, http } = setup({ apiKey: 's3cret' });
    api.definitions.list().subscribe();
    expect(http.expectOne('/api/workflows').request.headers.get('Authorization')).toBe(
      'Bearer s3cret',
    );

    TestBed.inject(HttpClient).get('https://example.org/other').subscribe();
    expect(http.expectOne('https://example.org/other').request.headers.has('Authorization')).toBe(
      false,
    );
  });

  it('asks the token function per request', () => {
    let token: string | null = null;
    const { api, http } = setup({ authToken: () => token });
    api.templates.list().subscribe();
    expect(http.expectOne('/api/templates').request.headers.has('Authorization')).toBe(false);
    token = 'sitzung-1';
    api.templates.list('page').subscribe();
    const req = http.expectOne((r) => r.url === '/api/templates');
    expect(req.request.headers.get('Authorization')).toBe('Bearer sitzung-1');
    expect(req.request.params.get('type')).toBe('page');
  });

  it('turns API errors into WfApiError with field details', async () => {
    const { api, http } = setup();
    const result = firstValueFrom(
      api.dataSources.createConnection({
        id: 'x',
        name: '',
        baseUrl: 'http://intern',
        authType: 'none',
      }),
    );
    http.expectOne('/api/data-sources/connections').flush(
      {
        error: {
          code: 'validation_error',
          message: 'Bitte prüfen.',
          details: { fields: { baseUrl: ['Nur HTTPS.'] } },
        },
      },
      { status: 422, statusText: 'Unprocessable Entity' },
    );
    const error = await result.catch((e: unknown) => e);
    expect(error).toBeInstanceOf(WfApiError);
    const apiError = error as WfApiError;
    expect(apiError.status).toBe(422);
    expect(apiError.code).toBe('validation_error');
    expect(apiError.fieldErrors()).toEqual({ baseUrl: ['Nur HTTPS.'] });
  });

  it('maps server and network failures', async () => {
    const { api, http } = setup();
    const server = firstValueFrom(api.definitions.get('x')).catch((e: unknown) => e as WfApiError);
    http.expectOne('/api/workflows/x').flush('<html>', { status: 502, statusText: 'Bad Gateway' });
    expect(((await server) as WfApiError).code).toBe('internal_error');

    const network = firstValueFrom(api.definitions.get('y')).catch((e: unknown) => e as WfApiError);
    http.expectOne('/api/workflows/y').error(new ProgressEvent('error'));
    expect(((await network) as WfApiError).code).toBe('network');
  });

  it('explains missing configuration', () => {
    TestBed.configureTestingModule({ providers: [provideHttpClient()] });
    expect(() => TestBed.inject(WorkflowApi).url('/x')).toThrowError(/provideWorkflowAdmin/);
  });
});
