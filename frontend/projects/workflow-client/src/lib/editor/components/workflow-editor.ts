import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  ElementRef,
  inject,
  input,
  untracked,
} from '@angular/core';
import { Router, ActivatedRoute } from '@angular/router';
import { WfI18n, WfTranslatePipe } from '../../i18n/i18n';
import { wfBeforeUnload, WfUnsavedChanges } from '../../shell/unsaved-changes';
import { WfBanner } from '../../ui/banner';
import { WfButton } from '../../ui/button';
import { EditorStore } from '../state/editor-store';
import { WfAddStepCards } from './add-step-cards';
import { valueOf } from './dom';
import { WfEditorHeader } from './editor-header';
import { WfFlowPreview } from './flow-preview';
import { WfEditorSide } from './side-panel';
import { WfStepDetail } from './step-detail';

/**
 * Der Editor als eigene Seite (9.3): Kopfleiste → Meldungen → Ablauf-Vorschau →
 * Arbeitsbereich (Seitenspalte 3/12, Detail 9/12) → Schritt hinzufügen.
 *
 * Routen: `workflows/neu`, `workflows/:id`, `workflows/:id/v/:version` (schreibgeschützt).
 */
@Component({
  selector: 'wf-workflow-editor',
  imports: [
    WfAddStepCards,
    WfBanner,
    WfButton,
    WfEditorHeader,
    WfEditorSide,
    WfFlowPreview,
    WfStepDetail,
    WfTranslatePipe,
  ],
  providers: [EditorStore],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-ed' },
  template: `
    <wf-editor-header (save)="save()" />

    @if (store.loadError(); as error) {
      <wf-banner tone="error">{{ error }}</wf-banner>
    }
    @if (store.message(); as message) {
      <wf-banner [tone]="message.tone" data-testid="editor-message">{{ message.text }}</wf-banner>
    }
    @if (store.readOnly()) {
      <wf-banner tone="info">{{ 'editor.readOnly' | wfT }}</wf-banner>
    }

    @if (store.loading()) {
      <p class="wf-muted">{{ 'editor.loading' | wfT }}</p>
    } @else if (store.view() === 'json') {
      <section class="wf-zone wf-ed-section">
        <label class="wf-lbl" for="wf-editor-json">{{ 'editor.json.label' | wfT }}</label>
        <textarea
          id="wf-editor-json"
          class="wf-inp wf-ed-json"
          spellcheck="false"
          [value]="store.jsonText()"
          (input)="store.jsonText.set(val($event))"
          data-testid="editor-json"
        ></textarea>
        <div class="wf-ed-row">
          <span class="wf-hint" style="flex: 1">{{ 'editor.json.hint' | wfT }}</span>
          <button type="button" wfButton (click)="store.applyJson()">
            {{ 'editor.json.apply' | wfT }}
          </button>
        </div>
      </section>
    } @else {
      @if (generalErrors().length > 0 || store.stepsWithErrors().size > 0) {
        <wf-banner tone="error" [heading]="issuesTitle()" data-testid="editor-issues">
          @for (issue of generalErrors(); track $index) {
            <span>{{ issue.message }}</span>
          }
          <span>{{ 'editor.issues.activeBlocked' | wfT }}</span>
        </wf-banner>
      }
      <wf-flow-preview />
      <div class="wf-g12">
        <wf-editor-side class="wf-c-3 wf-c-md-4 wf-c-sm-12" (addRequested)="scrollToCards()" />
        <wf-step-detail class="wf-c-9 wf-c-md-8 wf-c-sm-12" />
      </div>
      @if (!store.readOnly()) {
        <wf-add-step-cards (added)="focusDetail()" />
      }
    }
  `,
})
export class WfWorkflowEditor implements WfUnsavedChanges {
  /** Aus der Route (`withComponentInputBinding`). Fehlt sie, ist es ein neuer Workflow. */
  readonly id = input<string | undefined>(undefined);
  readonly version = input<string | undefined>(undefined);

  protected readonly store = inject(EditorStore);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  protected val = valueOf;

  protected readonly generalErrors = computed(() =>
    this.store.errors().filter((e) => e.step === null),
  );
  private readonly i18n = inject(WfI18n);
  protected readonly issuesTitle = computed(() => {
    const n = this.store.stepsWithErrors().size;
    return n === 1
      ? this.i18n.t('editor.issues.titleOne')
      : n > 1
        ? this.i18n.t('editor.issues.title', { n })
        : null;
  });

  constructor() {
    effect(() => {
      const id = this.id();
      const version = this.version();
      untracked(() => {
        if (id === undefined || id === '') {
          void this.store.newWorkflow();
        } else {
          void this.store.load(id, version === undefined ? undefined : Number(version));
        }
      });
    });
    wfBeforeUnload(() => this.hasUnsavedChanges());
  }

  hasUnsavedChanges(): boolean {
    return !this.store.readOnly() && this.store.dirty();
  }

  /** Für den Schutz vor Verlust (E-08). */
  save(): Promise<boolean> {
    const wasNew = this.store.isNew();
    return this.store.save().then((ok) => {
      if (ok && wasNew) {
        // Der neue Workflow bekommt seine eigene Adresse (E-07).
        void this.router.navigate(['..', this.store.model().id], {
          relativeTo: this.route,
          replaceUrl: true,
        });
      }
      return ok;
    });
  }

  protected scrollToCards(): void {
    this.host.nativeElement
      .querySelector('#wf-add-step')
      ?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
  }

  protected focusDetail(): void {
    queueMicrotask(() =>
      this.host.nativeElement.querySelector<HTMLInputElement>('[data-testid="step-name"]')?.focus(),
    );
  }
}
