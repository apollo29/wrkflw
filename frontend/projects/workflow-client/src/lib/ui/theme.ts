import { computed, DestroyRef, inject, Injectable, signal } from '@angular/core';
import { WF_CONFIG } from '../config/config';

export type WfResolvedTheme = 'hell' | 'dunkel';

/** Aktive Darstellung: aus der Konfiguration oder dem System, umschaltbar. */
@Injectable({ providedIn: 'root' })
export class WfThemeService {
  private readonly configured = inject(WF_CONFIG, { optional: true })?.theme ?? 'system';
  private readonly override = signal<WfResolvedTheme | null>(null);
  private readonly systemDark = signal(false);

  readonly theme = computed<WfResolvedTheme>(() => {
    const chosen = this.override() ?? (this.configured === 'system' ? null : this.configured);
    return chosen ?? (this.systemDark() ? 'dunkel' : 'hell');
  });

  constructor() {
    const media =
      typeof window !== 'undefined' && typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;
    if (media) {
      this.systemDark.set(media.matches);
      const listener = (e: MediaQueryListEvent): void => this.systemDark.set(e.matches);
      media.addEventListener('change', listener);
      inject(DestroyRef).onDestroy(() => media.removeEventListener('change', listener));
    }
  }

  set(theme: WfResolvedTheme | null): void {
    this.override.set(theme);
  }
}
