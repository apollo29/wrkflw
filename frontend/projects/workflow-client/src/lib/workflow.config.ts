import { InjectionToken } from '@angular/core';

/** Basis-URL der Workflow-API (z. B. "http://localhost:8080"). */
export const WORKFLOW_API_BASE_URL = new InjectionToken<string>('WORKFLOW_API_BASE_URL', {
  factory: () => '',
});

/**
 * Optionaler API-Key. Ist er gesetzt, fügt der authInterceptor ihn als
 * "Authorization: Bearer <key>" an Requests an die Workflow-API an.
 */
export const WORKFLOW_API_KEY = new InjectionToken<string | null>('WORKFLOW_API_KEY', {
  factory: () => null,
});
