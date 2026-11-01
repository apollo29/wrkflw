import { ChangeDetectionStrategy, Component, input } from '@angular/core';

export type WfBannerTone = 'ok' | 'error' | 'info';

/** Rückmeldung über einem Bereich: gespeichert, Fehler, Hinweis. */
@Component({
  selector: 'wf-banner',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '[attr.role]': 'tone() === "error" ? "alert" : "status"',
    '[class]': '"wf-banner--" + tone()',
  },
  template: `
    <span class="wf-banner__dot" aria-hidden="true"></span>
    <div class="wf-banner__body">
      @if (heading()) {
        <strong class="wf-banner__title">{{ heading() }}</strong>
      }
      <ng-content />
    </div>
  `,
  styles: `
    :host {
      display: flex;
      gap: 10px;
      align-items: flex-start;
      padding: 10px 14px;
      border: 1px solid;
      border-radius: var(--wfb-radius);
      font-size: var(--wfb-text-md);
    }
    :host(.wf-banner--ok) {
      background: var(--wfb-ok-bg);
      border-color: var(--wfb-ok-bd);
      color: var(--wfb-ok-fg);
    }
    :host(.wf-banner--error) {
      background: var(--wfb-err-bg);
      border-color: var(--wfb-err-bd);
      color: var(--wfb-err-fg);
    }
    :host(.wf-banner--info) {
      background: var(--wfb-primary-soft);
      border-color: var(--wfb-border);
      color: var(--wfb-text);
    }
    .wf-banner__dot {
      width: 7px;
      height: 7px;
      margin-top: 7px;
      border-radius: 50%;
      background: currentColor;
      flex: none;
    }
    .wf-banner__body {
      display: flex;
      flex-direction: column;
      gap: 2px;
      min-width: 0;
    }
    .wf-banner__title {
      font-size: 13px;
      font-weight: 600;
    }
    :host(.wf-banner--error) .wf-banner__body {
      color: var(--wfb-text);
    }
    :host(.wf-banner--error) .wf-banner__title {
      color: var(--wfb-err-fg);
    }
  `,
})
export class WfBanner {
  readonly tone = input<WfBannerTone>('info');
  readonly heading = input<string | null>(null);
}
