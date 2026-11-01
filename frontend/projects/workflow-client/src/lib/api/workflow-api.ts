import { HttpClient, HttpHeaders, HttpParams } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { catchError, Observable, throwError } from 'rxjs';
import { WF_CONFIG } from '../config/config';
import { WfApiError } from './api-error';
import type {
  ActionInfo,
  AuditEntry,
  Connection,
  ConnectionInput,
  ConnectionTest,
  CurrentStep,
  DefinitionSummary,
  DefinitionVersion,
  HistoryEntry,
  Instance,
  InstanceBrief,
  InstanceFilter,
  InstanceList,
  JsonObject,
  ModelInfo,
  ModelUsage,
  RestModel,
  SampleResult,
  SaveDefinitionRequest,
  SavedDefinition,
  StartRequest,
  Template,
  TemplateSummary,
  TemplateType,
  Trigger,
  UploadHandler,
  Usage,
  ValidationResult,
  VersionSummary,
} from './models';

type Method = 'GET' | 'POST' | 'PUT' | 'DELETE';
type Query = Readonly<Record<string, string | number | boolean | undefined>>;

/** Pfad aus Teilen; jeder Teil wird kodiert (IDs dürfen `/` oder Leerzeichen enthalten). */
export function apiPath(...parts: readonly (string | number)[]): string {
  return '/' + parts.map((p) => encodeURIComponent(String(p))).join('/');
}

/**
 * Typisierter Zugang zur Engine-API v3 (docs/openapi.yaml). Fehler kommen als
 * {@link WfApiError}. Der Anmelde-Header geht nur an `apiBaseUrl`.
 */
@Injectable({ providedIn: 'root' })
export class WorkflowApi {
  private readonly http = inject(HttpClient, { optional: true });
  private readonly config = inject(WF_CONFIG, { optional: true });

  /** Vollständige URL zu einem API-Pfad, z. B. für `httpResource`. */
  url(path: string): string {
    return this.requireConfig().apiBaseUrl + path;
  }

  /** Header für Anfragen an die API (Anmeldung). */
  headers(extra: Readonly<Record<string, string>> = {}): HttpHeaders {
    let headers = new HttpHeaders(extra as Record<string, string>);
    const token = this.requireConfig().authToken();
    if (token !== null && token !== '') {
      headers = headers.set('Authorization', `Bearer ${token}`);
    }
    return headers;
  }

  readonly instances = {
    start: (definitionId: string, body: StartRequest = {}): Observable<InstanceBrief> =>
      this.send('POST', apiPath('workflows', definitionId, 'instances'), body),
    list: (filter: InstanceFilter = {}): Observable<InstanceList> =>
      this.send('GET', '/instances', undefined, { ...filter }),
    get: (id: string): Observable<Instance> => this.send('GET', apiPath('instances', id)),
    currentStep: (id: string): Observable<CurrentStep> =>
      this.send('GET', apiPath('instances', id, 'current-step')),
    sendEvent: (
      id: string,
      event: string,
      payload: JsonObject = {},
      idempotencyKey?: string,
    ): Observable<InstanceBrief> =>
      this.send(
        'POST',
        apiPath('instances', id, 'events'),
        { event, payload },
        undefined,
        idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {},
      ),
    back: (id: string): Observable<InstanceBrief> =>
      this.send('POST', apiPath('instances', id, 'back')),
    cancel: (id: string): Observable<InstanceBrief> =>
      this.send('POST', apiPath('instances', id, 'cancel')),
    history: (id: string): Observable<{ instanceId: string; history: HistoryEntry[] }> =>
      this.send('GET', apiPath('instances', id, 'history')),
  };

  readonly definitions = {
    list: (): Observable<{ definitions: DefinitionSummary[] }> => this.send('GET', '/workflows'),
    get: (id: string): Observable<DefinitionVersion> => this.send('GET', apiPath('workflows', id)),
    versions: (id: string): Observable<{ id: string; versions: VersionSummary[] }> =>
      this.send('GET', apiPath('workflows', id, 'versions')),
    version: (id: string, version: number): Observable<DefinitionVersion> =>
      this.send('GET', apiPath('workflows', id, 'versions', version)),
    save: (id: string, body: SaveDefinitionRequest): Observable<SavedDefinition> =>
      this.send('POST', apiPath('workflows', id), body),
    validate: (definition: JsonObject): Observable<ValidationResult> =>
      this.send('POST', '/workflows/validate', { definition }),
    deleteVersion: (id: string, version: number): Observable<void> =>
      this.send('DELETE', apiPath('workflows', id, 'versions', version)),
  };

  readonly catalogs = {
    actions: (): Observable<{ actions: ActionInfo[] }> => this.send('GET', '/actions'),
    dataModels: (writable = false): Observable<{ models: ModelInfo[] }> =>
      this.send('GET', '/data-models', undefined, writable ? { writable: 1 } : {}),
    uploadHandlers: (): Observable<{ handlers: UploadHandler[] }> =>
      this.send('GET', '/upload-handlers'),
  };

  readonly templates = {
    list: (type?: TemplateType): Observable<{ templates: TemplateSummary[] }> =>
      this.send('GET', '/templates', undefined, { type }),
    get: (id: string): Observable<Template> => this.send('GET', apiPath('templates', id)),
    save: (id: string, body: Omit<Template, 'id'>): Observable<Template> =>
      this.send('POST', apiPath('templates', id), body),
    delete: (id: string): Observable<void> => this.send('DELETE', apiPath('templates', id)),
    usage: (id: string): Observable<{ templateId: string; usage: Usage[] }> =>
      this.send('GET', apiPath('templates', id, 'usage')),
  };

  readonly dataSources = {
    connections: (): Observable<{ connections: Connection[] }> =>
      this.send('GET', '/data-sources/connections'),
    connection: (id: string): Observable<Connection> =>
      this.send('GET', apiPath('data-sources', 'connections', id)),
    createConnection: (body: ConnectionInput & { id: string }): Observable<Connection> =>
      this.send('POST', '/data-sources/connections', body),
    updateConnection: (id: string, body: ConnectionInput): Observable<Connection> =>
      this.send('PUT', apiPath('data-sources', 'connections', id), body),
    replaceSecret: (id: string, secret: string): Observable<Connection> =>
      this.send('PUT', apiPath('data-sources', 'connections', id, 'secret'), { secret }),
    deleteConnection: (id: string): Observable<void> =>
      this.send('DELETE', apiPath('data-sources', 'connections', id)),
    testConnection: (id: string): Observable<ConnectionTest> =>
      this.send('POST', apiPath('data-sources', 'connections', id, 'test')),
    connectionUsage: (id: string): Observable<{ id: string; models: string[] }> =>
      this.send('GET', apiPath('data-sources', 'connections', id, 'usage')),
    models: (): Observable<{ models: RestModel[] }> => this.send('GET', '/data-sources/models'),
    model: (name: string): Observable<RestModel> =>
      this.send('GET', apiPath('data-sources', 'models', name)),
    createModel: (body: RestModel): Observable<RestModel> =>
      this.send('POST', '/data-sources/models', body),
    updateModel: (name: string, body: RestModel): Observable<RestModel> =>
      this.send('PUT', apiPath('data-sources', 'models', name), body),
    deleteModel: (name: string): Observable<void> =>
      this.send('DELETE', apiPath('data-sources', 'models', name)),
    modelUsage: (name: string): Observable<ModelUsage> =>
      this.send('GET', apiPath('data-sources', 'models', name, 'usage')),
    sample: (model: RestModel, id: string): Observable<SampleResult> =>
      this.send('POST', '/data-sources/models/sample', { model, id }),
    triggers: (): Observable<{ triggers: Trigger[] }> => this.send('GET', '/data-sources/triggers'),
    trigger: (id: string): Observable<Trigger> =>
      this.send('GET', apiPath('data-sources', 'triggers', id)),
    createTrigger: (body: Trigger): Observable<Trigger> =>
      this.send('POST', '/data-sources/triggers', body),
    updateTrigger: (id: string, body: Omit<Trigger, 'id'>): Observable<Trigger> =>
      this.send('PUT', apiPath('data-sources', 'triggers', id), body),
    deleteTrigger: (id: string): Observable<void> =>
      this.send('DELETE', apiPath('data-sources', 'triggers', id)),
    checkTrigger: (id: string): Observable<{ id: string; matches: number }> =>
      this.send('POST', apiPath('data-sources', 'triggers', id, 'check-now')),
    audit: (
      query: { type?: string; id?: string; limit?: number } = {},
    ): Observable<{ entries: AuditEntry[] }> =>
      this.send('GET', '/data-sources/audit', undefined, query),
  };

  private send<T>(
    method: Method,
    path: string,
    body?: unknown,
    query: Query = {},
    headers: Readonly<Record<string, string>> = {},
  ): Observable<T> {
    const http = this.http;
    if (http === null) {
      throw new Error(
        'wrkflw: HttpClient fehlt. Bitte provideHttpClient() in der Host-App angeben.',
      );
    }
    let params = new HttpParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    }
    return http
      .request<T>(method, this.url(path), {
        body: method === 'GET' || method === 'DELETE' ? undefined : (body ?? {}),
        headers: this.headers(headers),
        params,
      })
      .pipe(catchError((error: unknown) => throwError(() => WfApiError.from(error))));
  }

  private requireConfig(): NonNullable<typeof this.config> {
    if (this.config === null) {
      throw new Error(
        'wrkflw: Konfiguration fehlt. Bitte provideWorkflowAdmin({ apiBaseUrl }) angeben.',
      );
    }
    return this.config;
  }
}
