import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideZoneChangeDetection } from '@angular/core';
import { authInterceptor, WORKFLOW_API_BASE_URL } from '@apollo29/workflow-client';

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideHttpClient(withInterceptors([authInterceptor])),
    // Leer = same-origin: im Dev-Modus leitet der ng-serve-Proxy (proxy.conf.json)
    // /workflows und /instances an die API (http://127.0.0.1:8080) weiter -> kein CORS.
    { provide: WORKFLOW_API_BASE_URL, useValue: '' },
    // Optional: { provide: WORKFLOW_API_KEY, useValue: '<api-key>' },
  ],
};
