import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import type { DefinitionStructure } from '../api/models';
import { WfI18n } from '../i18n/i18n';
import { thumbnailLayout } from './thumbnail';

/** Kleines Ablaufbild ohne Text (E-63). */
@Component({
  selector: 'wf-flow-thumbnail',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-ov-thumb' },
  template: `
    <svg
      [attr.viewBox]="'0 0 ' + layout().width + ' 40'"
      preserveAspectRatio="xMinYMid meet"
      role="img"
      [attr.aria-label]="alt()"
    >
      @for (d of layout().paths; track $index) {
        <path [attr.d]="d" />
      }
      @for (s of layout().squares; track $index) {
        <rect
          [attr.x]="s.x"
          [attr.y]="s.y"
          width="8"
          height="8"
          rx="2"
          [class]="'wf-k-' + s.kind"
        />
      }
    </svg>
  `,
})
export class WfFlowThumbnail {
  readonly structure = input<DefinitionStructure | null>(null);
  private readonly i18n = inject(WfI18n);
  protected readonly layout = computed(() => thumbnailLayout(this.structure()));
  protected readonly alt = computed(() => {
    const { steps, branches } = this.layout();
    return branches === 0
      ? this.i18n.t('overview.thumb.altNone', { steps })
      : this.i18n.t('overview.thumb.alt', { steps, branches });
  });
}
