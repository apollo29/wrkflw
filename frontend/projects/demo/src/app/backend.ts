import { HttpInterceptorFn } from '@angular/common/http';
import { demoBackend } from './fake-api';

/**
 * Ersatz-Backend im Browser (Standard). Die Konfiguration «echt» tauscht diese Datei gegen
 * `backend.echt.ts` und spricht über den Proxy mit `php -S` (siehe docs/demo.md).
 */
export const DEMO_INTERCEPTORS: HttpInterceptorFn[] = [demoBackend];
