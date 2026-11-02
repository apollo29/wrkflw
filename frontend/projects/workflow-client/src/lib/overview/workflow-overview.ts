import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { WfApiError } from '../api/api-error';
import type { DefinitionStatus, DefinitionSummary, VersionSummary } from '../api/models';
import { WorkflowResources } from '../api/resources';
import { WorkflowApi } from '../api/workflow-api';
import { formatDate } from '../format';
import { WfI18n, WfTranslatePipe } from '../i18n/i18n';
import { WfBanner } from '../ui/banner';
import { WfButton } from '../ui/button';
import { WfDialogService } from '../ui/dialog';
import { WfIcon } from '../ui/icon';
import { definitionStatusPill, WfPill } from '../ui/pill';
import { WfSegment, WfSegmentOption } from '../ui/segment';
import { WfFlowThumbnail } from './flow-thumbnail';

type Filter = 'all' | DefinitionStatus;

interface Versions {
  readonly loading: boolean;
  readonly list: readonly VersionSummary[];
  readonly error: string | null;
}

/** Übersicht «Workflows» (9.2, E-60 bis E-68). */
@Component({
  selector: 'wf-workflow-overview',
  imports: [
    RouterLink,
    WfBanner,
    WfButton,
    WfFlowThumbnail,
    WfIcon,
    WfPill,
    WfSegment,
    WfTranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-ov' },
  template: `
    <div class="wf-ov-toolbar">
      <label class="wf-ov-search">
        <wf-icon name="search" />
        <input
          type="search"
          class="wf-inp"
          [attr.aria-label]="'overview.search' | wfT"
          [placeholder]="'overview.search' | wfT"
          [value]="query()"
          (input)="query.set($any($event.target).value)"
          data-testid="overview-search"
        />
      </label>
      <wf-segment
        [label]="'overview.filter' | wfT"
        [options]="filterOptions()"
        [(value)]="filter"
      />
      <span class="wf-ed-spacer"></span>
      <a wfButton variant="primary" routerLink="workflows/neu" data-testid="overview-new">{{
        'overview.new' | wfT
      }}</a>
    </div>

    @if (message(); as m) {
      <wf-banner [tone]="m.tone" data-testid="overview-message">{{ m.text }}</wf-banner>
    }
    @if (loadError(); as error) {
      <wf-banner tone="error">
        {{ error }}
        <button type="button" wfButton variant="text" size="sm" (click)="reload()">
          {{ 'action.retry' | wfT }}
        </button>
      </wf-banner>
    }

    <section class="wf-zone wf-ov-list" [attr.aria-label]="'overview.label' | wfT">
      @if (all().length === 0 && !resource.isLoading() && !loadError()) {
        <div class="wf-ov-empty">
          <p>{{ 'overview.empty' | wfT }}</p>
          <a wfButton variant="primary" routerLink="workflows/neu">{{ 'overview.new' | wfT }}</a>
        </div>
      } @else {
        <div class="wf-g12 wf-ov-row wf-ov-head wf-hide-sm" aria-hidden="true">
          <span class="wf-lbl wf-c-2">{{ 'overview.col.preview' | wfT }}</span>
          <span class="wf-lbl wf-c-3 wf-c-md-4">{{ 'overview.col.workflow' | wfT }}</span>
          <span class="wf-lbl wf-c-1">{{ 'overview.col.version' | wfT }}</span>
          <span class="wf-lbl wf-c-1 wf-c-md-2">{{ 'overview.col.status' | wfT }}</span>
          <span class="wf-lbl wf-c-1 wf-hide-md">{{ 'overview.col.steps' | wfT }}</span>
          <span class="wf-lbl wf-c-2">{{ 'overview.col.runs' | wfT }}</span>
          <span class="wf-lbl wf-c-1 wf-hide-md">{{ 'overview.col.changed' | wfT }}</span>
          <span class="wf-sr-only wf-c-1">{{ 'overview.col.versions' | wfT }}</span>
        </div>
        @for (d of visible(); track d.id) {
          @let pill = statusPill(d.status);
          <article class="wf-ov-item" [attr.data-workflow]="d.id">
            <div class="wf-g12 wf-ov-row" (click)="openRow($event, d)">
              <a
                class="wf-ov-thumb-link wf-c-2 wf-c-sm-12"
                [routerLink]="['workflows', d.id]"
                tabindex="-1"
              >
                <wf-flow-thumbnail [structure]="d.structure" />
              </a>
              <div class="wf-c-3 wf-c-md-4 wf-c-sm-12 wf-ov-name">
                <a [routerLink]="['workflows', d.id]" class="wf-ov-title">{{ d.name }}</a>
                <span class="wf-mono wf-muted">{{ d.id }}</span>
              </div>
              <span class="wf-c-1 wf-c-sm-3"
                ><wf-pill [mono]="true">v{{ d.version }}</wf-pill></span
              >
              <span class="wf-c-1 wf-c-md-2 wf-c-sm-4">
                <wf-pill [kind]="pill.kind" [dashed]="pill.dashed ?? false">{{
                  pill.label | wfT
                }}</wf-pill>
              </span>
              <span class="wf-c-1 wf-hide-md">{{ d.structure?.steps?.length ?? 0 }}</span>
              <span class="wf-c-2 wf-c-sm-12 wf-ov-runs">
                <span>{{ runs(d) }}</span>
                @for (o of d.olderRunning ?? []; track o.version) {
                  <span class="wf-hint">{{
                    'overview.runs.older' | wfT: { running: o.running, version: o.version }
                  }}</span>
                }
                <span class="wf-hint wf-ov-sm-only">{{
                  'overview.steps.meta'
                    | wfT: { n: d.structure?.steps?.length ?? 0, date: date(d.updatedAt) }
                }}</span>
              </span>
              <span class="wf-c-1 wf-hide-md">{{ date(d.updatedAt) }}</span>
              <span class="wf-c-1 wf-c-sm-12 wf-ov-expander">
                @if (d.olderVersions > 0) {
                  <button
                    type="button"
                    wfButton
                    variant="text"
                    size="sm"
                    [attr.aria-expanded]="isOpen(d.id)"
                    [attr.aria-controls]="'wf-vers-' + d.id"
                    (click)="toggle(d.id)"
                    data-testid="overview-older"
                  >
                    {{ 'overview.older' | wfT: { n: d.olderVersions } }}
                    <wf-icon name="chevron" [class.wf-ov-rot]="isOpen(d.id)" />
                  </button>
                }
              </span>
            </div>
            @if (isOpen(d.id)) {
              @let v = versions()[d.id];
              <div class="wf-ov-vers" [id]="'wf-vers-' + d.id">
                <span class="wf-lbl">{{ 'overview.older.title' | wfT }}</span>
                @if (!v || v.loading) {
                  <span class="wf-hint">{{ 'overview.older.loading' | wfT }}</span>
                } @else if (v.error) {
                  <span class="wf-hint" style="color: var(--wfb-err-fg)">{{ v.error }}</span>
                } @else {
                  @for (version of older(v.list); track version.version) {
                    <div class="wf-g12 wf-ov-vrow" [attr.data-version]="version.version">
                      <span class="wf-c-1 wf-c-sm-3"
                        ><wf-pill [mono]="true">v{{ version.version }}</wf-pill></span
                      >
                      <span class="wf-c-2 wf-c-sm-4"
                        ><wf-pill>{{ 'overview.archive' | wfT }}</wf-pill></span
                      >
                      <span class="wf-c-2 wf-c-sm-5">{{ versionRuns(version) }}</span>
                      <span class="wf-c-2 wf-hide-sm wf-muted">{{
                        'overview.version.changed' | wfT: { date: date(version.updatedAt) }
                      }}</span>
                      <a
                        class="wf-c-2 wf-c-sm-6"
                        [routerLink]="['workflows', d.id, 'v', version.version]"
                        >{{ 'overview.version.view' | wfT }}</a
                      >
                      <span class="wf-c-2 wf-hide-sm"></span>
                      <span class="wf-c-1 wf-c-sm-6 wf-ov-vx">
                        <button
                          type="button"
                          wfButton
                          variant="icon"
                          size="sm"
                          [disabled]="!version.deletable"
                          [attr.aria-label]="
                            (version.deletable
                              ? 'overview.version.remove'
                              : 'overview.version.removeLocked'
                            ) | wfT: { version: version.version }
                          "
                          [attr.title]="
                            version.deletable
                              ? null
                              : ('overview.version.locked' | wfT: { reason: lockReason(version) })
                          "
                          (click)="remove(d, version)"
                        >
                          <wf-icon name="close" />
                        </button>
                      </span>
                      @if (!version.deletable) {
                        <span class="wf-c-12 wf-hint">{{ lockReason(version) }}</span>
                      }
                    </div>
                  }
                }
              </div>
            }
          </article>
        } @empty {
          @if (all().length > 0) {
            <p class="wf-ov-empty">{{ 'overview.noMatch' | wfT }}</p>
          }
        }
      }
    </section>
    @if (all().length > 0) {
      <p class="wf-hint">{{ 'overview.footer' | wfT: { n: visible().length } }}</p>
    }
  `,
})
export class WfWorkflowOverview {
  private readonly api = inject(WorkflowApi);
  private readonly i18n = inject(WfI18n);
  private readonly dialogs = inject(WfDialogService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  protected readonly resource = inject(WorkflowResources).definitions();

  protected readonly query = signal('');
  protected readonly filter = signal<Filter>('all');
  protected readonly open = signal<ReadonlySet<string>>(new Set());
  protected readonly versions = signal<Readonly<Record<string, Versions>>>({});
  protected readonly message = signal<{ tone: 'ok' | 'error'; text: string } | null>(null);
  protected readonly statusPill = definitionStatusPill;
  protected readonly date = formatDate;

  protected readonly filterOptions = computed<WfSegmentOption<Filter>[]>(() => [
    { value: 'all', label: this.i18n.t('overview.filter.all') },
    { value: 'active', label: this.i18n.t('status.definition.active') },
    { value: 'draft', label: this.i18n.t('status.definition.draft') },
    { value: 'inactive', label: this.i18n.t('status.definition.inactive') },
  ]);

  protected readonly all = computed(() =>
    this.resource.hasValue() ? (this.resource.value()?.definitions ?? []) : [],
  );
  protected readonly visible = computed(() => {
    const q = this.query().trim().toLocaleLowerCase('de');
    const f = this.filter();
    return this.all().filter(
      (d) =>
        (f === 'all' || d.status === f) &&
        (q === '' ||
          d.name.toLocaleLowerCase('de').includes(q) ||
          d.id.toLocaleLowerCase('de').includes(q)),
    );
  });
  protected readonly loadError = computed(() => {
    const error = this.resource.error();
    return error
      ? this.errorText(WfApiError.from((error as { cause?: unknown }).cause ?? error))
      : null;
  });

  protected reload(): void {
    this.resource.reload();
  }

  protected runs(d: DefinitionSummary): string {
    return d.totalInstances === 0
      ? this.i18n.t('overview.runs.none')
      : this.i18n.t('overview.runs', { running: d.runningInstances, total: d.totalInstances });
  }

  protected versionRuns(v: VersionSummary): string {
    if (v.totalInstances === 0) {
      return this.i18n.t('overview.runs.none');
    }
    return v.runningInstances > 0
      ? this.i18n.t('overview.version.runs.running', { n: v.runningInstances })
      : this.i18n.t('overview.version.runs.done', { n: v.totalInstances });
  }

  protected lockReason(v: VersionSummary): string {
    return this.i18n.t(
      v.runningInstances > 0 ? 'overview.version.referencedRunning' : 'overview.version.referenced',
    );
  }

  protected older(list: readonly VersionSummary[]): readonly VersionSummary[] {
    return list.filter((v) => v.deleteBlockedBy !== 'latest');
  }

  protected isOpen(id: string): boolean {
    return this.open().has(id);
  }

  /** Klick auf die Zeile öffnet den Editor (E-64); Knöpfe und Links behalten ihre Wirkung. */
  protected openRow(event: MouseEvent, d: DefinitionSummary): void {
    if ((event.target as HTMLElement).closest('a, button, input, select')) {
      return;
    }
    void this.router.navigate(['workflows', d.id], { relativeTo: this.route });
  }

  protected toggle(id: string): void {
    const next = new Set(this.open());
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
      if (!this.versions()[id] || this.versions()[id]?.error) {
        void this.loadVersions(id);
      }
    }
    this.open.set(next);
  }

  private async loadVersions(id: string): Promise<void> {
    this.versions.update((v) => ({ ...v, [id]: { loading: true, list: [], error: null } }));
    try {
      const result = await firstValueFrom(this.api.definitions.versions(id));
      this.versions.update((v) => ({
        ...v,
        [id]: { loading: false, list: result.versions, error: null },
      }));
    } catch (error) {
      this.versions.update((v) => ({
        ...v,
        [id]: { loading: false, list: [], error: this.errorText(WfApiError.from(error)) },
      }));
    }
  }

  /** Entfernen mit Rückfrage (9.14); ein Rückgängig gibt es nach dem Löschen nicht. */
  protected async remove(d: DefinitionSummary, v: VersionSummary): Promise<void> {
    this.message.set(null);
    const ok = await this.dialogs.confirm({
      title: this.i18n.t('overview.version.confirm.title', { version: v.version }),
      text: this.i18n.t('overview.version.confirm.text', { id: d.id, version: v.version }),
      cancelValue: false,
      actions: [
        { value: false, label: this.i18n.t('action.cancel') },
        {
          value: true,
          label: this.i18n.t('overview.version.remove', { version: v.version }),
          variant: 'danger',
        },
      ],
    });
    if (!ok) {
      return;
    }
    try {
      await firstValueFrom(this.api.definitions.deleteVersion(d.id, v.version));
      this.message.set({
        tone: 'ok',
        text: this.i18n.t('overview.version.removed', { id: d.id, version: v.version }),
      });
      await this.loadVersions(d.id);
      this.resource.reload();
    } catch (error) {
      this.message.set({ tone: 'error', text: this.errorText(WfApiError.from(error)) });
    }
  }

  private errorText(error: WfApiError): string {
    const key = `error.${error.code}`;
    const known = this.i18n.tDynamic(key);
    return known !== key ? known : error.message || this.i18n.t('error.unknown');
  }
}
