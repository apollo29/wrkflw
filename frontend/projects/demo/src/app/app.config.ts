import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideZoneChangeDetection } from '@angular/core';
import { authInterceptor, WORKFLOW_API_BASE_URL } from 'workflow-client';

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    provideHttpClient(withInterceptors([authInterceptor])),
    // Basis-URL der Workflow-API. Bei Bedarf an die echte Umgebung anpassen.
    { provide: WORKFLOW_API_BASE_URL, useValue: 'http://localhost:8080' },
    // Optional: { provide: WORKFLOW_API_KEY, useValue: '<api-key>' },
  ],
};
