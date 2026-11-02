import { ChangeDetectionStrategy, Component, inject, output } from '@angular/core';
import { WfTranslatePipe } from '../../i18n/i18n';
import { WfIcon } from '../../ui/icon';
import { STEP_KINDS, stepKindInfo, StepKind } from '../../ui/step-kinds';
import { EditorStore } from '../state/editor-store';

/** «Schritt hinzufügen» (9.9): sechs Karten, 2/4/6 von 12 Spalten. */
@Component({
  selector: 'wf-add-step-cards',
  imports: [WfIcon, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-zone wf-ed-section', id: 'wf-add-step' },
  template: `
    <h3>{{ 'editor.add.title' | wfT }}</h3>
    <div class="wf-g12 wf-ed-cards">
      @for (kind of kinds; track kind.kind) {
        <button
          type="button"
          class="wf-ed-tcard wf-c-2 wf-c-md-4 wf-c-sm-6"
          [class]="kind.colorClass"
          (click)="add(kind.kind)"
          [attr.data-kind]="kind.kind"
        >
          <span class="wf-ed-tcard__title"
            ><wf-icon [name]="kind.icon" /> {{ kind.label | wfT }}</span
          >
          <span class="wf-ed-tcard__text">{{ kind.description | wfT }}</span>
        </button>
      }
    </div>
  `,
})
export class WfAddStepCards {
  private readonly store = inject(EditorStore);
  protected readonly kinds = STEP_KINDS.map(stepKindInfo);
  readonly added = output<string>();

  protected add(kind: StepKind): void {
    this.store.add(kind);
    const name = this.store.selected();
    if (name) {
      this.added.emit(name);
    }
  }
}
