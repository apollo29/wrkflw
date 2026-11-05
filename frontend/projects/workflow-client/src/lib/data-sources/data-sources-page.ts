import { ChangeDetectionStrategy, Component, computed, inject, viewChild } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute } from '@angular/router';
import { map } from 'rxjs';
import { wfBeforeUnload, WfUnsavedChanges } from '../shell/unsaved-changes';
import { WfConnectionEditor } from './connection-editor';
import { WfModelEditor } from './model-editor';
import { WfTriggerEditor } from './trigger-editor';
import { SOURCE_AREAS, SourceArea } from './source-parts';

/**
 * Reiter «Datenquellen» (9.16): Verbindungen, Datenmodelle und Trigger. Bereich und Auswahl
 * stehen in der Adresse (`?bereich=modelle&id=antrag`), damit sie sich verlinken lassen.
 */
@Component({
  selector: 'wf-data-sources',
  imports: [WfConnectionEditor, WfModelEditor, WfTriggerEditor],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @switch (area()) {
      @case ('modelle') {
        <wf-model-editor [selectedId]="id()" />
      }
      @case ('trigger') {
        <wf-trigger-editor [selectedId]="id()" />
      }
      @default {
        <wf-connection-editor [selectedId]="id()" />
      }
    }
  `,
})
export class WfDataSourcesPage implements WfUnsavedChanges {
  private readonly route = inject(ActivatedRoute);
  private readonly params = toSignal(
    this.route.queryParamMap.pipe(map((p) => ({ area: p.get('bereich'), id: p.get('id') }))),
    { initialValue: { area: null, id: null } },
  );
  protected readonly area = computed<SourceArea>(() => {
    const area = this.params().area as SourceArea | null;
    return area && SOURCE_AREAS.includes(area) ? area : 'verbindungen';
  });
  protected readonly id = computed(() => this.params().id);

  private readonly connection = viewChild(WfConnectionEditor);
  private readonly model = viewChild(WfModelEditor);
  private readonly trigger = viewChild(WfTriggerEditor);
  private readonly editor = computed(
    () => this.connection() ?? this.model() ?? this.trigger() ?? null,
  );

  constructor() {
    wfBeforeUnload(() => this.hasUnsavedChanges());
  }

  hasUnsavedChanges(): boolean {
    return this.editor()?.hasUnsavedChanges() ?? false;
  }

  save(): Promise<boolean> {
    return this.editor()?.save() ?? Promise.resolve(true);
  }
}
