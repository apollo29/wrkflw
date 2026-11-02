import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { WfTranslatePipe } from '../i18n/i18n';
import type { WfMessageKey } from '../i18n/messages.de';

/** Platzhalter für Bereiche, die in späteren Phasen kommen. */
@Component({
  selector: 'wf-placeholder-page',
  imports: [WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="wf-zone">
      <h2>{{ title() | wfT }}</h2>
      <p class="wf-muted">{{ 'page.placeholder' | wfT: { phase: phase() } }}</p>
    </section>
  `,
})
export class WfPlaceholderPage {
  /** Aus den Routendaten (`withComponentInputBinding`) oder direkt gesetzt. */
  readonly title = input<WfMessageKey>('nav.workflows');
  readonly phase = input(7);
}
