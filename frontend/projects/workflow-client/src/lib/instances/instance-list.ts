import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  signal,
  untracked,
} from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom, map } from 'rxjs';
import { WfApiError } from '../api/api-error';
import type { InstanceStatus, InstanceSummary } from '../api/models';
import { WorkflowResources } from '../api/resources';
import { WorkflowApi } from '../api/workflow-api';
import { formatDateTime, formatShort } from '../format';
import { WfI18n, WfTranslatePipe } from '../i18n/i18n';
import { WfBanner } from '../ui/banner';
import { WfButton } from '../ui/button';
import { WfDialogService } from '../ui/dialog';
import { instanceStatusPill, WfPill } from '../ui/pill';
import { WfToastService } from '../ui/toast';
import { describeHistory, HistoryLine } from './history-text';

export const INSTANCE_PAGE_SIZE = 50;

const STATUSES: readonly InstanceStatus[] = [
  'running',
  'waiting_event',
  'waiting_timer',
  'completed',
  'failed',
  'cancelled',
];

const STRUCTURE_KIND: Record<string, string> = {
  interactive: 'interactive',
  timer: 'timer',
  automatic: 'automatic',
  workflow: 'workflow',
  check: 'data-check',
  write: 'data-write',
};

interface History {
  readonly loading: boolean;
  readonly lines: readonly HistoryLine[];
  readonly error: string | null;
}

/** Reiter «Instanzen»: Durchläufe mit Status, Verlauf und Abbrechen. */
@Component({
  selector: 'wf-instance-list',
  imports: [WfBanner, WfButton, WfPill, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-in' },
  template: `
    <div class="wf-in-toolbar">
      <div class="wf-in-toolbar" role="group" [attr.aria-label]="'instances.filter' | wfT">
        <button
          type="button"
          class="wf-in-chip wf-k-neutral"
          [attr.aria-pressed]="status() === null"
          (click)="setStatus(null)"
        >
          {{ 'instances.filter.all' | wfT }}
        </button>
        @for (s of statuses; track s) {
          @let pill = statusPill(s);
          <button
            type="button"
            class="wf-in-chip"
            [class]="'wf-k-' + pill.kind"
            [attr.aria-pressed]="status() === s"
            (click)="setStatus(s)"
          >
            <span class="wf-in-chip__dot" aria-hidden="true"></span>{{ pill.label | wfT }}
          </button>
        }
      </div>
      <span class="wf-ed-spacer"></span>
      <button type="button" wfButton (click)="refresh()" [busy]="loading()">
        {{ 'action.refresh' | wfT }}
      </button>
    </div>

    @if (error(); as e) {
      <wf-banner tone="error">{{ e }}</wf-banner>
    }

    <section class="wf-zone wf-in-zone" [attr.aria-label]="'instances.label' | wfT">
      <table class="wf-in-table">
        <thead>
          <tr>
            <th class="wf-lbl">{{ 'instances.col.workflow' | wfT }}</th>
            <th class="wf-lbl">{{ 'instances.col.subject' | wfT }}</th>
            <th class="wf-lbl">{{ 'instances.col.step' | wfT }}</th>
            <th class="wf-lbl">{{ 'instances.col.status' | wfT }}</th>
            <th class="wf-lbl">{{ 'instances.col.next' | wfT }}</th>
            <th class="wf-lbl">{{ 'instances.col.updated' | wfT }}</th>
            <th>
              <span class="wf-sr-only">{{ 'instances.col.history' | wfT }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          @for (i of instances(); track i.id) {
            @let pill = statusPill(i.status);
            <tr [attr.data-instance]="i.id">
              <td>
                <div style="font-weight: 500">{{ i.definitionName }}</div>
                <div class="wf-mono wf-muted">
                  {{ i.definitionId }} · v{{ i.definitionVersion }}
                </div>
              </td>
              <td class="wf-mono">{{ i.subjectId ?? '—' }}</td>
              <td>
                <span class="wf-in-sq" [class]="'wf-k-' + stepKind(i)" aria-hidden="true"></span
                >{{ i.currentStep }}
              </td>
              <td>
                <wf-pill [kind]="pill.kind" [dot]="true">{{ pill.label | wfT }}</wf-pill>
                @if (i.status === 'failed' && i.lastError) {
                  <span class="wf-in-error" data-testid="instance-error">{{ i.lastError }}</span>
                }
              </td>
              <td>{{ i.wakeAt ? time(i.wakeAt) : '—' }}</td>
              <td>{{ time(i.updatedAt) }}</td>
              <td class="wf-in-actions">
                <button
                  type="button"
                  wfButton
                  variant="text"
                  size="sm"
                  [attr.aria-expanded]="openId() === i.id"
                  [attr.aria-controls]="'wf-hist-' + i.id"
                  (click)="toggle(i)"
                  data-testid="instance-history"
                >
                  {{ 'instances.history' | wfT }}
                </button>
              </td>
            </tr>
            @if (openId() === i.id) {
              @let h = history();
              <tr class="wf-in-hist" [id]="'wf-hist-' + i.id">
                <td colspan="7">
                  <span class="wf-lbl">{{ 'instances.history.title' | wfT }}</span>
                  @if (!h || h.loading) {
                    <p class="wf-hint">{{ 'instances.history.loading' | wfT }}</p>
                  } @else if (h.error) {
                    <p class="wf-hint" style="color: var(--wfb-err-fg)">{{ h.error }}</p>
                  } @else {
                    <ol>
                      @for (line of h.lines; track $index) {
                        <li [class.wf-in-hist__error]="line.error">
                          <span class="wf-mono wf-muted">{{ short(line.at) }}</span
                          ><span>{{ line.text }}</span>
                        </li>
                      } @empty {
                        <li>{{ 'instances.history.empty' | wfT }}</li>
                      }
                    </ol>
                  }
                  @if (!finished(i)) {
                    <button
                      type="button"
                      wfButton
                      variant="danger"
                      size="sm"
                      (click)="cancel(i)"
                      data-testid="instance-cancel"
                    >
                      {{ 'instances.cancel' | wfT }}
                    </button>
                  }
                </td>
              </tr>
            }
          } @empty {
            @if (!loading()) {
              <tr>
                <td colspan="7" class="wf-muted">
                  {{ (status() ? 'instances.emptyFiltered' : 'instances.empty') | wfT }}
                </td>
              </tr>
            }
          }
        </tbody>
      </table>
    </section>

    @if (hasMore()) {
      <button
        type="button"
        wfButton
        class="wf-in-more"
        [busy]="loading()"
        (click)="more()"
        data-testid="instances-more"
      >
        {{ 'instances.more' | wfT }}
      </button>
    }
    @if (counts(); as c) {
      <p class="wf-hint">{{ 'instances.footer' | wfT: c }}</p>
    }
  `,
})
export class WfInstanceList {
  private readonly api = inject(WorkflowApi);
  private readonly i18n = inject(WfI18n);
  private readonly dialogs = inject(WfDialogService);
  private readonly toasts = inject(WfToastService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly definitions = inject(WorkflowResources).definitions();

  protected readonly statuses = STATUSES;
  protected readonly statusPill = instanceStatusPill;
  protected readonly time = formatDateTime;
  protected readonly short = formatShort;

  /** Filter aus der Adresse (`?status=failed`), damit die Ansicht verlinkbar ist. */
  protected readonly status = toSignal(
    this.route.queryParamMap.pipe(
      map((p) =>
        (STATUSES as readonly string[]).includes(p.get('status') ?? '')
          ? (p.get('status') as InstanceStatus)
          : null,
      ),
    ),
    { initialValue: null },
  );

  protected readonly instances = signal<readonly InstanceSummary[]>([]);
  protected readonly loading = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly hasMore = signal(false);
  protected readonly counts = signal<{ total: number; running: number } | null>(null);
  protected readonly openId = signal<string | null>(null);
  protected readonly history = signal<History | null>(null);

  private readonly kinds = computed(() => {
    const out = new Map<string, string>();
    for (const d of this.definitions.hasValue()
      ? (this.definitions.value()?.definitions ?? [])
      : []) {
      for (const s of d.structure?.steps ?? []) {
        out.set(`${d.id}|${s.name}`, STRUCTURE_KIND[s.kind] ?? 'automatic');
      }
    }
    return out;
  });

  constructor() {
    effect(() => {
      this.status();
      untracked(() => void this.load(false));
    });
  }

  protected stepKind(i: InstanceSummary): string {
    return this.kinds().get(`${i.definitionId}|${i.currentStep}`) ?? 'neutral';
  }

  protected finished(i: InstanceSummary): boolean {
    return i.status === 'completed' || i.status === 'failed' || i.status === 'cancelled';
  }

  protected setStatus(status: InstanceStatus | null): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { status },
      replaceUrl: true,
    });
  }

  protected refresh(): void {
    this.definitions.reload();
    void this.load(false);
  }

  protected more(): void {
    void this.load(true);
  }

  private async load(append: boolean): Promise<void> {
    this.loading.set(true);
    this.error.set(null);
    const offset = append ? this.instances().length : 0;
    try {
      const result = await firstValueFrom(
        this.api.instances.list({
          ...(this.status() ? { status: this.status()! } : {}),
          limit: INSTANCE_PAGE_SIZE,
          offset,
        }),
      );
      this.instances.set(append ? [...this.instances(), ...result.instances] : result.instances);
      this.hasMore.set(result.instances.length === INSTANCE_PAGE_SIZE);
      const c = result.counts;
      const total = Object.values(c).reduce((a, b) => a + b, 0);
      this.counts.set({
        total,
        running: (c.running ?? 0) + (c.waiting_event ?? 0) + (c.waiting_timer ?? 0),
      });
      if (!append) {
        this.openId.set(null);
      }
    } catch (error) {
      this.error.set(this.errorText(WfApiError.from(error)));
    } finally {
      this.loading.set(false);
    }
  }

  protected async toggle(i: InstanceSummary): Promise<void> {
    if (this.openId() === i.id) {
      this.openId.set(null);
      return;
    }
    this.openId.set(i.id);
    this.history.set({ loading: true, lines: [], error: null });
    try {
      const result = await firstValueFrom(this.api.instances.history(i.id));
      if (this.openId() === i.id) {
        this.history.set({
          loading: false,
          lines: describeHistory(result.history, (k, p) => this.i18n.tDynamic(k, p)),
          error: null,
        });
      }
    } catch (error) {
      this.history.set({
        loading: false,
        lines: [],
        error: this.errorText(WfApiError.from(error)),
      });
    }
  }

  /** Abbrechen mit Rückfrage; lässt sich nicht rückgängig machen. */
  protected async cancel(i: InstanceSummary): Promise<void> {
    const ok = await this.dialogs.confirm({
      title: this.i18n.t('instances.cancel.title'),
      text: this.i18n.t('instances.cancel.text', { id: i.subjectId ?? i.id }),
      cancelValue: false,
      actions: [
        { value: false, label: this.i18n.t('instances.cancel.keep') },
        { value: true, label: this.i18n.t('instances.cancel.confirm'), variant: 'danger' },
      ],
    });
    if (!ok) {
      return;
    }
    try {
      await firstValueFrom(this.api.instances.cancel(i.id));
      this.toasts.show({ message: this.i18n.t('instances.cancelled') });
      await this.load(false);
    } catch (error) {
      this.error.set(this.errorText(WfApiError.from(error)));
    }
  }

  private errorText(error: WfApiError): string {
    const key = `error.${error.code}`;
    const known = this.i18n.tDynamic(key);
    return known !== key ? known : error.message || this.i18n.t('error.unknown');
  }
}
