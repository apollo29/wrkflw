import { ChangeDetectionStrategy, Component, inject, Injectable, signal } from '@angular/core';
import { WfI18n, WfTranslatePipe } from '../i18n/i18n';
import { WfButton } from './button';
import { WfIcon } from './icon';

export interface WfToastOptions {
  readonly message: string;
  /** Text der Aktion, z. B. «Rückgängig». */
  readonly actionLabel?: string;
  readonly onAction?: () => void;
  /** Standard 6000 ms. */
  readonly durationMs?: number;
}

export interface WfToast extends WfToastOptions {
  readonly id: number;
}

export interface WfToastHandle {
  dismiss(): void;
}

interface Timer {
  handle: ReturnType<typeof setTimeout> | null;
  remaining: number;
  startedAt: number;
}

/** Kurze Meldungen unten, optional mit «Rückgängig» (Löschen mit Undo). */
@Injectable({ providedIn: 'root' })
export class WfToastService {
  static readonly DEFAULT_DURATION_MS = 6000;

  private readonly list = signal<readonly WfToast[]>([]);
  private readonly timers = new Map<number, Timer>();
  private nextId = 0;

  readonly toasts = this.list.asReadonly();

  show(options: WfToastOptions): WfToastHandle {
    const toast: WfToast = { ...options, id: ++this.nextId };
    this.list.update((list) => [...list, toast]);
    this.timers.set(toast.id, {
      handle: null,
      remaining: options.durationMs ?? WfToastService.DEFAULT_DURATION_MS,
      startedAt: 0,
    });
    this.resume(toast.id);
    return { dismiss: () => this.dismiss(toast.id) };
  }

  dismiss(id: number): void {
    const timer = this.timers.get(id);
    if (timer?.handle) {
      clearTimeout(timer.handle);
    }
    this.timers.delete(id);
    this.list.update((list) => list.filter((t) => t.id !== id));
  }

  act(id: number): void {
    const toast = this.list().find((t) => t.id === id);
    this.dismiss(id);
    toast?.onAction?.();
  }

  /** Bei Fokus oder Mauszeiger: Zeit anhalten, damit niemand die Aktion verpasst. */
  pause(id: number): void {
    const timer = this.timers.get(id);
    if (timer?.handle) {
      clearTimeout(timer.handle);
      timer.handle = null;
      timer.remaining = Math.max(0, timer.remaining - (Date.now() - timer.startedAt));
    }
  }

  resume(id: number): void {
    const timer = this.timers.get(id);
    if (timer && timer.handle === null) {
      timer.startedAt = Date.now();
      timer.handle = setTimeout(() => this.dismiss(id), timer.remaining);
    }
  }
}

@Component({
  selector: 'wf-toast-host',
  imports: [WfButton, WfIcon, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { role: 'region', '[attr.aria-label]': 'regionLabel' },
  template: `
    <div class="wf-toasts" aria-live="polite">
      @for (toast of service.toasts(); track toast.id) {
        <div
          class="wf-toast"
          (mouseenter)="service.pause(toast.id)"
          (mouseleave)="service.resume(toast.id)"
          (focusin)="service.pause(toast.id)"
          (focusout)="service.resume(toast.id)"
        >
          <span class="wf-toast__msg">{{ toast.message }}</span>
          @if (toast.actionLabel) {
            <button type="button" wfButton variant="text" size="sm" (click)="service.act(toast.id)">
              {{ toast.actionLabel }}
            </button>
          }
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'action.close' | wfT"
            (click)="service.dismiss(toast.id)"
          >
            <wf-icon name="close" />
          </button>
        </div>
      }
    </div>
  `,
  styles: `
    .wf-toasts {
      position: fixed;
      z-index: 1000;
      left: 50%;
      bottom: 16px;
      transform: translateX(-50%);
      display: flex;
      flex-direction: column;
      gap: 8px;
      width: min(480px, calc(100vw - 32px));
      pointer-events: none;
    }
    .wf-toast {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 8px 8px 14px;
      pointer-events: auto;
      border-radius: var(--wfb-radius);
      background: var(--wfb-text);
      color: var(--wfb-bg);
      box-shadow: var(--wfb-shadow-dialog);
      font-size: var(--wfb-text-md);
    }
    .wf-toast__msg {
      flex: 1;
      min-width: 0;
    }
    .wf-toast .wf-btn--text {
      color: var(--wfb-bg);
      text-decoration: underline;
    }
    .wf-toast .wf-btn--icon {
      color: var(--wfb-bg);
      border-color: transparent;
      background: transparent;
    }
  `,
})
export class WfToastHost {
  protected readonly service = inject(WfToastService);
  protected readonly regionLabel = inject(WfI18n).t('toast.region');
}
