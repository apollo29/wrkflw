import { provideHttpClient, withFetch, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideWorkflowAdmin } from '@apollo29/workflow-client';
import { routes } from './app.routes';
import { DEMO_INTERCEPTORS } from './backend';
import { demoUpload, demoUploadImage } from './demo-upload';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes, withComponentInputBinding()),
    // Ersatz-Backend im Browser; `ng serve demo -c echt` spricht mit der Engine (docs/demo.md).
    provideHttpClient(withFetch(), withInterceptors(DEMO_INTERCEPTORS)),
    provideWorkflowAdmin({
      apiBaseUrl: '/api',
      uploadFile: demoUpload,
      uploadImage: demoUploadImage,
    }),
  ],
};
