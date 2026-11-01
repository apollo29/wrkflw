import {
  afterRenderEffect,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  input,
  signal,
} from '@angular/core';

let nextId = 0;

/**
 * Label, Hinweis und Fehler für genau ein Eingabeelement im Inhalt. Verknüpft `id`,
 * `aria-describedby` und `aria-invalid`, damit Screenreader alles vorlesen.
 *
 * ```html
 * <wf-field [label]="'…' | wfT" [hint]="…" [error]="errors()['name']?.[0]">
 *   <input class="wf-inp" [(ngModel)]="name" />
 * </wf-field>
 * ```
 */
@Component({
  selector: 'wf-field',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.wf-field--invalid]': '!!error()' },
  template: `
    <label class="wf-field__label" [attr.for]="controlId()">{{ label() }}</label>
    <ng-content />
    @if (hint()) {
      <span class="wf-hint" [id]="hintId">{{ hint() }}</span>
    }
    @if (error()) {
      <span class="wf-field__error" [id]="errorId">{{ error() }}</span>
    }
  `,
  styles: `
    :host {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 0;
    }
    .wf-field__label {
      font-size: var(--wfb-text-sm);
      font-weight: 500;
    }
    .wf-field__error {
      font-size: var(--wfb-text-sm);
      color: var(--wfb-err-fg);
    }
  `,
})
export class WfField {
  readonly label = input.required<string>();
  readonly hint = input<string | null | undefined>(null);
  readonly error = input<string | null | undefined>(null);

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly baseId = `wf-field-${++nextId}`;
  protected readonly hintId = `${this.baseId}-hint`;
  protected readonly errorId = `${this.baseId}-error`;

  protected readonly controlId = signal(this.baseId);

  constructor() {
    afterRenderEffect(() => {
      const control = this.control();
      if (!control) {
        return;
      }
      if (!control.id) {
        control.id = this.baseId;
      }
      if (this.controlId() !== control.id) {
        this.controlId.set(control.id);
      }
      const described = [
        this.hint() ? this.hintId : null,
        this.error() ? this.errorId : null,
      ].filter((id) => id !== null);
      if (described.length > 0) {
        control.setAttribute('aria-describedby', described.join(' '));
      } else {
        control.removeAttribute('aria-describedby');
      }
      if (this.error()) {
        control.setAttribute('aria-invalid', 'true');
      } else {
        control.removeAttribute('aria-invalid');
      }
    });
  }

  private control(): HTMLElement | null {
    return this.host.nativeElement.querySelector(
      'input, select, textarea, [contenteditable="true"]',
    );
  }
}
