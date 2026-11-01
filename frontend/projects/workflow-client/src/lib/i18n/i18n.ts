import { inject, Injectable, InjectionToken, Pipe, PipeTransform } from '@angular/core';
import { MESSAGES_DE, WfMessageKey, WfMessages } from './messages.de';

/** Der aktive Nachrichtenkatalog. Standard: Deutsch. */
export const WF_MESSAGES = new InjectionToken<WfMessages>('WF_MESSAGES', {
  providedIn: 'root',
  factory: () => MESSAGES_DE,
});

export type WfMessageParams = Readonly<Record<string, string | number>>;

/** Setzt `{name}`-Platzhalter ein; unbekannte bleiben stehen, damit Fehler auffallen. */
export function formatMessage(text: string, params?: WfMessageParams): string {
  if (!params) {
    return text;
  }
  return text.replace(/\{(\w+)\}/g, (match, name: string) =>
    name in params ? String(params[name]) : match,
  );
}

/** Texte der Oberfläche über den Katalog. */
@Injectable({ providedIn: 'root' })
export class WfI18n {
  private readonly messages = inject(WF_MESSAGES);

  t(key: WfMessageKey, params?: WfMessageParams): string {
    const text = this.messages[key] as string | undefined;
    return text === undefined || text === '' ? key : formatMessage(text, params);
  }

  /** Für zusammengesetzte Schlüssel wie `status.instance.${status}`. */
  tDynamic(key: string, params?: WfMessageParams): string {
    return key in this.messages ? this.t(key as WfMessageKey, params) : key;
  }
}

/** `{{ 'nav.workflows' | wfT }}` oder `{{ 'page.placeholder' | wfT: { phase: 7 } }}` */
@Pipe({ name: 'wfT' })
export class WfTranslatePipe implements PipeTransform {
  private readonly i18n = inject(WfI18n);

  transform(key: WfMessageKey | string, params?: WfMessageParams): string {
    return this.i18n.tDynamic(key, params);
  }
}
