import { ChangeDetectionStrategy, Component, computed, inject, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import type { DefinitionStatus } from '../../api/models';
import { WfI18n, WfTranslatePipe } from '../../i18n/i18n';
import { WfButton } from '../../ui/button';
import { WfPill } from '../../ui/pill';
import { WfSegment, WfSegmentOption } from '../../ui/segment';
import { EditorStore, EditorView } from '../state/editor-store';
import { valueOf } from './dom';

/** Kopfleiste des Editors (9.4). */
@Component({
  selector: 'wf-editor-header',
  imports: [RouterLink, WfButton, WfPill, WfSegment, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-zone wf-ed-head', role: 'banner' },
  template: `
    <a wfButton variant="text" [routerLink]="backLink()">{{ 'nav.backToWorkflows' | wfT }}</a>
    <span class="wf-ed-divider" aria-hidden="true"></span>
    @if (store.view() === 'json' || store.readOnly()) {
      <span class="wf-mono wf-ed-head__text-id">{{ store.model().id }}</span>
      <span class="wf-ed-head__text-name">{{ store.model().name || store.model().id }}</span>
    } @else {
      <input
        class="wf-inp wf-inp--ghost wf-mono wf-ed-head__id"
        [attr.aria-label]="'editor.id' | wfT"
        [placeholder]="'editor.id.placeholder' | wfT"
        [value]="store.model().id"
        [readonly]="!store.isNew()"
        (input)="setId($event)"
        data-testid="editor-id"
      />
      <input
        class="wf-inp wf-inp--ghost wf-ed-head__name"
        [attr.aria-label]="'editor.name' | wfT"
        [placeholder]="'editor.name' | wfT"
        [value]="store.model().name"
        (input)="setName($event)"
        data-testid="editor-name"
      />
    }
    @if (store.isNew()) {
      <wf-pill [dashed]="true" [mono]="true" data-testid="editor-version">{{
        'editor.version.new' | wfT
      }}</wf-pill>
    } @else {
      <wf-pill
        [mono]="true"
        [attr.title]="'editor.version.title' | wfT"
        data-testid="editor-version"
        >v{{ store.version() }}</wf-pill
      >
    }
    @if (store.dirty()) {
      <wf-pill kind="timer" [dot]="true" data-testid="editor-dirty">{{
        'state.unsaved' | wfT
      }}</wf-pill>
    }
    <span class="wf-ed-spacer"></span>
    @if (!store.readOnly()) {
      <wf-segment
        [label]="'editor.status.label' | wfT"
        [options]="statusOptions()"
        [value]="store.status()"
        (valueChange)="store.setStatus($event)"
        data-testid="editor-status"
      />
    }
    <wf-segment
      [label]="'editor.view.label' | wfT"
      [options]="viewOptions()"
      [value]="store.view()"
      (valueChange)="setView($event)"
    />
    @if (!store.readOnly()) {
      <button
        type="button"
        wfButton
        variant="primary"
        [disabled]="!store.canSave()"
        [busy]="store.busy()"
        (click)="save.emit()"
        data-testid="editor-save"
      >
        {{ 'action.save' | wfT }}
      </button>
    }
  `,
})
export class WfEditorHeader {
  protected readonly store = inject(EditorStore);
  private readonly i18n = inject(WfI18n);
  readonly save = output<void>();

  protected readonly backLink = computed(() =>
    this.store.isNew() ? '..' : this.store.readOnly() ? '../../..' : '../..',
  );

  protected readonly statusOptions = computed<WfSegmentOption<DefinitionStatus>[]>(() => [
    { value: 'active', label: this.i18n.t('status.definition.active'), ok: true },
    { value: 'draft', label: this.i18n.t('status.definition.draft') },
    { value: 'inactive', label: this.i18n.t('status.definition.inactive') },
  ]);

  protected readonly viewOptions = computed<WfSegmentOption<EditorView>[]>(() => [
    { value: 'visual', label: this.i18n.t('editor.view.visual') },
    { value: 'json', label: this.i18n.t('editor.view.json') },
  ]);

  protected setId(event: Event): void {
    const id = valueOf(event);
    this.store.update((m) => ({ ...m, id }));
  }

  protected setName(event: Event): void {
    const name = valueOf(event);
    this.store.update((m) => ({ ...m, name }));
  }

  protected setView(view: EditorView): void {
    this.store.setView(view);
  }
}
