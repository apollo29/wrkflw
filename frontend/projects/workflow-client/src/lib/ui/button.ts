import { Directive, input } from '@angular/core';

export type WfButtonVariant = 'default' | 'primary' | 'text' | 'icon' | 'danger';
export type WfButtonSize = 'sm' | 'md';

/**
 * Knöpfe auf nativen Elementen: `<button wfButton variant="primary">`. Gestaltung in
 * `styles/base.css` (`.wf-btn`), damit sie auch ohne Komponente gilt.
 */
@Directive({
  selector: 'button[wfButton], a[wfButton]',
  host: {
    class: 'wf-btn',
    '[class.wf-btn--primary]': 'variant() === "primary"',
    '[class.wf-btn--text]': 'variant() === "text"',
    '[class.wf-btn--icon]': 'variant() === "icon"',
    '[class.wf-btn--danger]': 'variant() === "danger"',
    '[class.wf-btn--sm]': 'size() === "sm"',
    '[attr.aria-busy]': 'busy() ? "true" : null',
  },
})
export class WfButton {
  readonly variant = input<WfButtonVariant>('default');
  readonly size = input<WfButtonSize>('md');
  readonly busy = input(false);
}
