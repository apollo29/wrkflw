import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { WfTranslatePipe } from '../i18n/i18n';
import type { WfMessageKey } from '../i18n/messages.de';
import { WfThemeService } from '../ui/theme';
import { WfToastHost } from '../ui/toast';

interface Tab {
  readonly path: string;
  readonly label: WfMessageKey;
  readonly exact: boolean;
}

/** Rahmen der Admin-Oberfläche: Titel, Reiter, Inhalt, Meldungen. */
@Component({
  selector: 'wf-admin-shell',
  imports: [RouterOutlet, RouterLink, RouterLinkActive, WfTranslatePipe, WfToastHost],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf', '[attr.data-theme]': 'theme.theme()' },
  template: `
    <div class="wf-shell">
      <header class="wf-shell__head">
        <h1>{{ 'app.title' | wfT }}</h1>
        <nav class="wf-tabs" [attr.aria-label]="'nav.label' | wfT">
          @for (tab of tabs; track tab.path) {
            <a
              class="wf-tab"
              [routerLink]="tab.path"
              routerLinkActive="wf-tab--on"
              ariaCurrentWhenActive="page"
              [routerLinkActiveOptions]="{ exact: tab.exact }"
              >{{ tab.label | wfT }}</a
            >
          }
        </nav>
      </header>
      <main class="wf-shell__main">
        <router-outlet />
      </main>
    </div>
    <wf-toast-host />
  `,
  styles: `
    :host {
      display: block;
      min-height: 100%;
    }
    .wf-shell {
      max-width: 1440px;
      margin: 0 auto;
      padding: 28px 32px 56px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }
    .wf-shell__head {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    .wf-tabs {
      display: flex;
      gap: 4px;
      border-bottom: 1px solid var(--wfb-border);
      overflow-x: auto;
    }
    .wf-tab {
      padding: 10px 12px;
      margin-bottom: -1px;
      border-bottom: 2px solid transparent;
      color: var(--wfb-text-muted);
      font-weight: 500;
      text-decoration: none;
      white-space: nowrap;
    }
    .wf-tab:hover {
      color: var(--wfb-text);
    }
    .wf-tab--on {
      color: var(--wfb-text);
      border-bottom-color: var(--wfb-primary);
    }
    @container wf (max-width: 767px) {
      .wf-shell {
        padding: 20px 16px 40px;
      }
      .wf-shell__head h1 {
        font-size: 20px;
      }
      .wf-tab {
        padding: 12px 10px;
      }
    }
  `,
})
export class WfAdminShell {
  protected readonly theme = inject(WfThemeService);
  protected readonly tabs: readonly Tab[] = [
    { path: '.', label: 'nav.workflows', exact: true },
    { path: 'instanzen', label: 'nav.instances', exact: false },
    { path: 'vorlagen', label: 'nav.templates', exact: false },
    { path: 'datenquellen', label: 'nav.dataSources', exact: false },
  ];
}
