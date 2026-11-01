import { HttpErrorResponse } from '@angular/common/http';
import { ApiErrorBody, ValidationError } from './models';

/**
 * Ein Fehler der Engine-API in einheitlicher Form (ADR 11). `code` ist `network`, wenn
 * der Server nicht antwortet, sonst der Code aus `{error: {code}}`.
 */
export class WfApiError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
    readonly details: ApiErrorBody['error']['details'] = undefined,
  ) {
    super(message);
    this.name = 'WfApiError';
  }

  static from(error: unknown): WfApiError {
    if (error instanceof WfApiError) {
      return error;
    }
    if (error instanceof HttpErrorResponse) {
      if (error.status === 0) {
        return new WfApiError(0, 'network', error.message);
      }
      const body = error.error as Partial<ApiErrorBody> | null | undefined;
      const inner = body && typeof body === 'object' ? body.error : undefined;
      if (inner && typeof inner.code === 'string') {
        return new WfApiError(error.status, inner.code, inner.message ?? '', inner.details);
      }
      return new WfApiError(
        error.status,
        error.status >= 500 ? 'internal_error' : 'unknown',
        error.message,
      );
    }
    return new WfApiError(0, 'unknown', error instanceof Error ? error.message : String(error));
  }

  /** Fehler je Feld für Formulare (`details.fields`). */
  fieldErrors(): Readonly<Record<string, readonly string[]>> {
    return this.details?.fields ?? {};
  }

  /** Prüfbericht einer Definition (`details.errors`). */
  validationErrors(): readonly ValidationError[] {
    return this.details?.errors ?? [];
  }

  /** Fehlende Pflichtangaben beim Start (`details.missing`). */
  missingInputs(): readonly string[] {
    return this.details?.missing ?? [];
  }
}
