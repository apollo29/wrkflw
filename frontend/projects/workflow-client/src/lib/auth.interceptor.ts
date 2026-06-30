import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { WORKFLOW_API_KEY } from './workflow.config';

/**
 * Hängt – falls ein API-Key konfiguriert ist – einen Bearer-Authorization-Header
 * an ausgehende Requests an. Als functional Interceptor via
 * provideHttpClient(withInterceptors([authInterceptor])) registrierbar.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const apiKey = inject(WORKFLOW_API_KEY);

  if (apiKey) {
    return next(req.clone({ setHeaders: { Authorization: `Bearer ${apiKey}` } }));
  }

  return next(req);
};
