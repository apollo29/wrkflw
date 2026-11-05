import { HttpInterceptorFn } from '@angular/common/http';

/** Echtes Backend: keine Ersatz-Antworten, `/api` geht über den Proxy an die Engine. */
export const DEMO_INTERCEPTORS: HttpInterceptorFn[] = [];
