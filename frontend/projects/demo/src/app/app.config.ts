import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideWorkflowAdmin } from '@apollo29/workflow-client';
import { routes } from './app.routes';
import { demoBackend } from './fake-api';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withFetch(), withInterceptors([demoBackend])),
    // Ersatz-Backend im Browser; Phase 12 bindet das echte Backend an.
    provideWorkflowAdmin({ apiBaseUrl: '/api' }),
  ],
};
