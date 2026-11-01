import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { WfTranslatePipe } from '../i18n/i18n';
import type { WfMessageKey } from '../i18n/messages.de';
import { WfButton } from '../ui/button';
import { WfPill } from '../ui/pill';
import { WfUnsavedChanges, wfBeforeUnload } from './unsaved-changes';

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

/**
 * Platzhalter des Editors mit dem Schutz vor Verlust (E-08). Der Knopf simuliert eine
 * Änderung, bis der echte Editor in Phase 7 kommt.
 */
@Component({
  selector: 'wf-editor-placeholder',
  imports: [WfTranslatePipe, WfButton, WfPill, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="wf-zone wf-editor-ph">
      <a routerLink="../.." class="wf-editor-ph__back">{{ 'nav.backToWorkflows' | wfT }}</a>
      <h2>{{ 'page.editor.title' | wfT: { id: id() } }}</h2>
      @if (version(); as v) {
        <p class="wf-muted">{{ 'page.editor.version' | wfT: { version: v } }}</p>
      }
      <p class="wf-muted">{{ 'page.placeholder' | wfT: { phase: 7 } }}</p>
      <div class="wf-editor-ph__row">
        <button type="button" wfButton data-testid="simulate-change" (click)="dirty.set(true)">
          {{ 'action.add' | wfT }}
        </button>
        @if (dirty()) {
          <wf-pill kind="timer" [dot]="true">{{ 'state.unsaved' | wfT }}</wf-pill>
        }
      </div>
    </section>
  `,
  styles: `
    .wf-editor-ph {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }
    .wf-editor-ph__row {
      display: flex;
      align-items: center;
      gap: 10px;
    }
  `,
})
export class WfEditorPlaceholder implements WfUnsavedChanges {
  readonly id = input('');
  readonly version = input<string | undefined>(undefined);
  readonly dirty = signal(false);

  constructor() {
    wfBeforeUnload(() => this.dirty());
  }

  hasUnsavedChanges(): boolean {
    return this.dirty();
  }

  async save(): Promise<boolean> {
    this.dirty.set(false);
    return true;
  }
}
