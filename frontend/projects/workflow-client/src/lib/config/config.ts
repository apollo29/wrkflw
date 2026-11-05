import { EnvironmentProviders, InjectionToken, makeEnvironmentProviders } from '@angular/core';
import { WF_MESSAGES } from '../i18n/i18n';
import { MESSAGES_DE, WfMessages } from '../i18n/messages.de';

export type WfTheme = 'hell' | 'dunkel' | 'system';

/** Ergebnis eines Uploads: gespeicherter Wert (z. B. Datei-ID) oder Ablehnung mit Grund (F-54). */
export type WfUploadResult = { readonly value: string } | { readonly rejected: string };

export interface WfUploadContext {
  /** Datei-Prüfung des Feldes, wie im Editor gewählt. */
  readonly handler: string | null;
  readonly instanceId: string;
  readonly step: string;
  readonly field: string;
}

/** Lädt ein Bild für den HTML-Editor hoch und gibt seine öffentliche `https:`-Adresse zurück. */
export type WfUploadImage = (file: File) => Promise<string>;

/** Nimmt eine Datei für ein Datei-Feld an. Die Engine selbst nimmt keine Dateien an. */
export type WfUploadFile = (file: File, context: WfUploadContext) => Promise<WfUploadResult>;

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
  /** Upload für Datei-Felder im Runner; ohne sie zeigt der Runner nur einen Hinweis. */
  readonly uploadFile?: WfUploadFile;
  /** Bild-Upload im HTML-Editor; ohne ihn gibt es nur «Bild-URL». */
  readonly uploadImage?: WfUploadImage;
}

export interface ResolvedWorkflowAdminConfig {
  readonly apiBaseUrl: string;
  readonly authToken: () => string | null;
  readonly theme: WfTheme;
  readonly uploadFile: WfUploadFile | null;
  readonly uploadImage: WfUploadImage | null;
}

export const WF_CONFIG = new InjectionToken<ResolvedWorkflowAdminConfig>('WF_CONFIG');

export function resolveConfig(config: WorkflowAdminConfig): ResolvedWorkflowAdminConfig {
  const base = config.apiBaseUrl.trim().replace(/\/+$/, '');
  const key = config.apiKey;
  return {
    apiBaseUrl: base,
    authToken: config.authToken ?? (() => (key !== undefined && key !== '' ? key : null)),
    theme: config.theme ?? 'system',
    uploadFile: config.uploadFile ?? null,
    uploadImage: config.uploadImage ?? null,
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
