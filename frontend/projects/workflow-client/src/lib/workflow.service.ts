import { HttpClient } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import { WORKFLOW_API_BASE_URL } from './workflow.config';
import {
  ActionCatalogResponse,
  CurrentStep,
  DefinitionListResponse,
  DefinitionResponse,
  HistoryResponse,
  InstanceState,
  InstanceSummary,
  SaveDefinitionResponse,
  TemplateDetail,
  TemplateListResponse,
  TemplateType,
  TemplateUsageResponse,
  WorkflowLifecycle,
} from './workflow.models';

/**
 * Schmaler Client für die Workflow-Engine-REST-API. Kapselt die Endpunkte;
 * das Polling/Rendering übernimmt der WorkflowRunnerComponent.
 */
@Injectable({ providedIn: 'root' })
export class WorkflowService {
  private readonly http = inject(HttpClient);
  private readonly baseUrl = inject(WORKFLOW_API_BASE_URL);

  start(
    definition: string,
    context: Record<string, unknown> = {},
    subject?: { type?: string; id?: string },
  ): Observable<InstanceSummary> {
    return this.http.post<InstanceSummary>(
      `${this.baseUrl}/workflows/${encodeURIComponent(definition)}/instances`,
      { context, subjectType: subject?.type, subjectId: subject?.id },
    );
  }

  getInstance(id: string): Observable<InstanceState> {
    return this.http.get<InstanceState>(`${this.baseUrl}/instances/${encodeURIComponent(id)}`);
  }

  currentStep(id: string): Observable<CurrentStep> {
    return this.http.get<CurrentStep>(
      `${this.baseUrl}/instances/${encodeURIComponent(id)}/current-step`,
    );
  }

  sendEvent(
    id: string,
    event: string,
    payload: Record<string, unknown> = {},
  ): Observable<InstanceSummary> {
    return this.http.post<InstanceSummary>(
      `${this.baseUrl}/instances/${encodeURIComponent(id)}/events`,
      { event, payload },
    );
  }

  history(id: string): Observable<HistoryResponse> {
    return this.http.get<HistoryResponse>(
      `${this.baseUrl}/instances/${encodeURIComponent(id)}/history`,
    );
  }

  // -- Definition-Verwaltung (Editor) --------------------------------------

  listDefinitions(): Observable<DefinitionListResponse> {
    return this.http.get<DefinitionListResponse>(`${this.baseUrl}/workflows`);
  }

  getDefinition(id: string): Observable<DefinitionResponse> {
    return this.http.get<DefinitionResponse>(
      `${this.baseUrl}/workflows/${encodeURIComponent(id)}`,
    );
  }

  saveDefinition(
    id: string,
    name: string,
    definition: Record<string, unknown>,
    status: WorkflowLifecycle = 'active',
  ): Observable<SaveDefinitionResponse> {
    return this.http.post<SaveDefinitionResponse>(
      `${this.baseUrl}/workflows/${encodeURIComponent(id)}`,
      { name, definition, status },
    );
  }

  /** Katalog der verfügbaren Actions inkl. Config-Schema (GET /actions). */
  listActions(): Observable<ActionCatalogResponse> {
    return this.http.get<ActionCatalogResponse>(`${this.baseUrl}/actions`);
  }

  // -- Wiederverwendbare Templates ----------------------------------------

  listTemplates(type?: TemplateType): Observable<TemplateListResponse> {
    const query = type ? `?type=${encodeURIComponent(type)}` : '';
    return this.http.get<TemplateListResponse>(`${this.baseUrl}/templates${query}`);
  }

  getTemplate(id: string): Observable<TemplateDetail> {
    return this.http.get<TemplateDetail>(`${this.baseUrl}/templates/${encodeURIComponent(id)}`);
  }

  saveTemplate(
    id: string,
    name: string,
    subject: string,
    body: string,
    type: TemplateType = 'email',
  ): Observable<{ id: string }> {
    return this.http.post<{ id: string }>(
      `${this.baseUrl}/templates/${encodeURIComponent(id)}`,
      { name, subject, body, type },
    );
  }

  deleteTemplate(id: string): Observable<{ id: string; deleted: boolean }> {
    return this.http.delete<{ id: string; deleted: boolean }>(
      `${this.baseUrl}/templates/${encodeURIComponent(id)}`,
    );
  }

  /** Welche Workflow-Schritte referenzieren dieses Template. */
  templateUsage(id: string): Observable<TemplateUsageResponse> {
    return this.http.get<TemplateUsageResponse>(
      `${this.baseUrl}/templates/${encodeURIComponent(id)}/usage`,
    );
  }
}
