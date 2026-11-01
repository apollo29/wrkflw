import {
  afterNextRender,
  ApplicationRef,
  ChangeDetectionStrategy,
  Component,
  createComponent,
  ElementRef,
  EnvironmentInjector,
  inject,
  Injectable,
  input,
  output,
  viewChild,
} from '@angular/core';
import { WfButton, WfButtonVariant } from './button';
import { WfThemeService } from './theme';

export interface WfDialogAction<T> {
  readonly value: T;
  readonly label: string;
  readonly variant?: WfButtonVariant;
}

export interface WfConfirmOptions<T> {
  readonly title: string;
  readonly text?: string;
  /** In Anzeigereihenfolge; der erste Knopf bekommt den Fokus. */
  readonly actions: readonly WfDialogAction<T>[];
  /** Ergebnis bei Esc. */
  readonly cancelValue: T;
}

let nextId = 0;

/** Rückfrage als modaler Dialog (natives `<dialog>`). */
@Component({
  selector: 'wf-confirm-dialog',
  imports: [WfButton],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <dialog
      #dialog
      class="wf-dialog"
      [attr.aria-labelledby]="titleId"
      [attr.aria-describedby]="options().text ? textId : null"
      (cancel)="onCancel($event)"
    >
      <h2 [id]="titleId">{{ options().title }}</h2>
      @if (options().text) {
        <p [id]="textId" class="wf-dialog__text">{{ options().text }}</p>
      }
      <div class="wf-dialog__actions">
        @for (action of options().actions; track $index) {
          <button
            type="button"
            wfButton
            [variant]="action.variant ?? 'default'"
            (click)="closed.emit(action.value)"
          >
            {{ action.label }}
          </button>
        }
      </div>
    </dialog>
  `,
  styles: `
    .wf-dialog {
      width: min(480px, calc(100% - 32px));
      margin: 12vh auto auto;
      padding: 22px 24px;
      border: 1px solid var(--wfb-border);
      border-radius: var(--wfb-radius);
      background: var(--wfb-bg);
      color: var(--wfb-text);
      box-shadow: var(--wfb-shadow-dialog);
    }
    .wf-dialog[open] {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }
    .wf-dialog::backdrop {
      background: var(--wfb-scrim);
    }
    .wf-dialog__text {
      margin: 0;
      color: var(--wfb-text-muted);
    }
    .wf-dialog__actions {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 4px;
    }
  `,
})
export class WfConfirmDialog<T = unknown> {
  readonly options = input.required<WfConfirmOptions<T>>();
  readonly closed = output<T>();

  private readonly dialog = viewChild.required<ElementRef<HTMLDialogElement>>('dialog');
  private readonly id = ++nextId;
  protected readonly titleId = `wf-dialog-${this.id}-title`;
  protected readonly textId = `wf-dialog-${this.id}-text`;

  constructor() {
    afterNextRender(() => {
      const dialog = this.dialog().nativeElement;
      if (typeof dialog.showModal === 'function') {
        dialog.showModal();
      } else {
        dialog.setAttribute('open', '');
      }
      dialog.querySelector<HTMLButtonElement>('.wf-dialog__actions button')?.focus();
    });
  }

  protected onCancel(event: Event): void {
    event.preventDefault();
    this.closed.emit(this.options().cancelValue);
  }
}

/** Öffnet Rückfragen ausserhalb von Templates, z. B. aus einem Guard. */
@Injectable({ providedIn: 'root' })
export class WfDialogService {
  private readonly appRef = inject(ApplicationRef);
  private readonly injector = inject(EnvironmentInjector);
  private readonly theme = inject(WfThemeService);

  confirm<T>(options: WfConfirmOptions<T>): Promise<T> {
    return new Promise<T>((resolve) => {
      // Eigene `.wf`-Wurzel, damit Tokens auch ausserhalb der Shell gelten.
      const host = document.createElement('div');
      host.className = 'wf wf-dialog-host';
      host.setAttribute('data-theme', this.theme.theme());
      document.body.appendChild(host);
      const ref = createComponent(WfConfirmDialog<T>, {
        environmentInjector: this.injector,
        hostElement: host.appendChild(document.createElement('wf-confirm-dialog')),
      });
      ref.setInput('options', options);
      const previous =
        document.activeElement instanceof HTMLElement ? document.activeElement : null;
      const sub = ref.instance.closed.subscribe((value) => {
        sub.unsubscribe();
        this.appRef.detachView(ref.hostView);
        ref.destroy();
        host.remove();
        previous?.focus();
        resolve(value);
      });
      this.appRef.attachView(ref.hostView);
      ref.changeDetectorRef.detectChanges();
    });
  }
}
