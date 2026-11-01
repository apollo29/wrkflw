import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  Injector,
  input,
  model,
} from '@angular/core';

export interface WfSegmentOption<T extends string = string> {
  readonly value: T;
  readonly label: string;
  /** Grün hervorheben, wenn gewählt (z. B. «Aktiv»). */
  readonly ok?: boolean;
  readonly disabled?: boolean;
}

/**
 * Umschalter mit wenigen Optionen (Status, Ansicht). Tastatur wie eine Radiogruppe:
 * Pfeiltasten wählen und fokussieren, nur die gewählte Option ist im Tab-Ablauf.
 */
@Component({
  selector: 'wf-segment',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { role: 'radiogroup', '[attr.aria-label]': 'label()', '(keydown)': 'onKey($event)' },
  template: `
    @for (option of options(); track option.value) {
      <button
        type="button"
        role="radio"
        class="wf-seg__item"
        [class.wf-seg__item--on]="option.value === value()"
        [class.wf-seg__item--ok]="option.ok && option.value === value()"
        [attr.aria-checked]="option.value === value()"
        [attr.tabindex]="option.value === value() ? 0 : -1"
        [disabled]="option.disabled"
        (click)="select(option.value)"
      >
        {{ option.label }}
      </button>
    }
  `,
  styles: `
    :host {
      display: inline-flex;
      gap: 2px;
      padding: 3px;
      max-width: 100%;
      border: 1px solid var(--wfb-border);
      border-radius: 9px;
      background: var(--wfb-bg-soft);
    }
    .wf-seg__item {
      height: var(--wfb-control-h-sm);
      padding: 0 12px;
      border: 0;
      border-radius: var(--wfb-radius-xs);
      background: transparent;
      color: var(--wfb-text-muted);
      font: inherit;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      white-space: nowrap;
    }
    .wf-seg__item:hover:not(:disabled):not(.wf-seg__item--on) {
      color: var(--wfb-text);
    }
    .wf-seg__item--on {
      background: var(--wfb-primary);
      color: var(--wfb-on-primary);
    }
    .wf-seg__item--ok {
      background: var(--wfb-ok-bg);
      color: var(--wfb-ok-fg);
      box-shadow: inset 0 0 0 1px var(--wfb-ok-bd);
    }
    .wf-seg__item:disabled {
      opacity: 0.45;
      cursor: not-allowed;
    }
    @container wf (max-width: 767px) {
      :host {
        display: flex;
        width: 100%;
      }
      .wf-seg__item {
        flex: 1;
      }
    }
  `,
})
export class WfSegment<T extends string = string> {
  readonly options = input.required<readonly WfSegmentOption<T>[]>();
  readonly value = model.required<T>();
  readonly label = input.required<string>();

  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly injector = inject(Injector);

  select(value: T): void {
    this.value.set(value);
  }

  protected onKey(event: KeyboardEvent): void {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
    const enabled = this.options().filter((o) => !o.disabled);
    if (step === undefined && event.key !== 'Home' && event.key !== 'End') {
      return;
    }
    event.preventDefault();
    const index = enabled.findIndex((o) => o.value === this.value());
    const next =
      event.key === 'Home'
        ? 0
        : event.key === 'End'
          ? enabled.length - 1
          : (index + (step ?? 0) + enabled.length) % enabled.length;
    const option = enabled[next];
    if (option) {
      this.select(option.value);
      // Erst nach dem Rendern ist die neue Option markiert.
      afterNextRender(
        () => this.host.nativeElement.querySelector<HTMLElement>('[aria-checked="true"]')?.focus(),
        { injector: this.injector },
      );
    }
  }
}
