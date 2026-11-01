import { provideHttpClient, withFetch } from '@angular/common/http';
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideWorkflowAdmin } from '@apollo29/workflow-client';
import { routes } from './app.routes';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withFetch()),
    // Das Backend der Demo folgt in Phase 12; bis dahin zeigt die Oberfläche Platzhalter.
    provideWorkflowAdmin({ apiBaseUrl: '/api' }),
  ],
};
