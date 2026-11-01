import { EnvironmentProviders, InjectionToken, makeEnvironmentProviders } from '@angular/core';
import { WF_MESSAGES } from '../i18n/i18n';
import { MESSAGES_DE, WfMessages } from '../i18n/messages.de';

export type WfTheme = 'hell' | 'dunkel' | 'system';

/** Einstellungen der Host-App für die Admin-Oberfläche. */
export interface WorkflowAdminConfig {
  /** Basis-URL der Engine-API, z. B. `/api` oder `https://host/wrkflw`. */
  readonly apiBaseUrl: string;
  /** Fester Schlüssel für `Authorization: Bearer …` (ApiKeyAccessResolver). */
  readonly apiKey?: string;
  /** Alternativ: Token je Anfrage, z. B. aus der Sitzung der Host-App. */
  readonly authToken?: () => string | null;
  /** Ersetzt einzelne Texte des deutschen Katalogs oder alle. */
  readonly messages?: Partial<WfMessages>;
  /** Standard: `system` (folgt `prefers-color-scheme`). */
  readonly theme?: WfTheme;
}

export interface ResolvedWorkflowAdminConfig {
  readonly apiBaseUrl: string;
  readonly authToken: () => string | null;
  readonly theme: WfTheme;
}

export const WF_CONFIG = new InjectionToken<ResolvedWorkflowAdminConfig>('WF_CONFIG');

export function resolveConfig(config: WorkflowAdminConfig): ResolvedWorkflowAdminConfig {
  const base = config.apiBaseUrl.trim().replace(/\/+$/, '');
  const key = config.apiKey;
  return {
    apiBaseUrl: base,
    authToken: config.authToken ?? (() => (key !== undefined && key !== '' ? key : null)),
    theme: config.theme ?? 'system',
  };
}

/**
 * Bindet die Admin-Oberfläche ein. `provideHttpClient()` gibt die Host-App selbst an.
 *
 * ```ts
 * providers: [provideHttpClient(withFetch()), provideWorkflowAdmin({ apiBaseUrl: '/api' })]
 * ```
 */
export function provideWorkflowAdmin(config: WorkflowAdminConfig): EnvironmentProviders {
  return makeEnvironmentProviders([
    { provide: WF_CONFIG, useValue: resolveConfig(config) },
    { provide: WF_MESSAGES, useValue: { ...MESSAGES_DE, ...config.messages } },
  ]);
}
