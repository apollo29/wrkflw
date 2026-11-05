import {
  ChangeDetectionStrategy,
  Component,
  computed,
  Directive,
  effect,
  inject,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { WfApiError } from '../api/api-error';
import type { AuditEntry } from '../api/models';
import { WorkflowApi } from '../api/workflow-api';
import { formatDateTime } from '../format';
import { WfI18n, WfTranslatePipe } from '../i18n/i18n';
import { wfConfirmDiscard } from '../shell/unsaved-changes';
import { WfButton } from '../ui/button';
import { WfDialogService } from '../ui/dialog';
import { WfSegment, WfSegmentOption } from '../ui/segment';
import type { FormErrors } from './data-source-forms';

export type SourceArea = 'verbindungen' | 'modelle' | 'trigger';
export const SOURCE_AREAS: readonly SourceArea[] = ['verbindungen', 'modelle', 'trigger'];

export interface SourceListItem {
  readonly id: string;
  readonly title: string;
  readonly sub: string;
}

/** Linke Spalte: Umschalter der Bereiche, Einträge, «+ Neu» (Boards 9.16). */
@Component({
  selector: 'wf-ds-list',
  imports: [WfButton, WfSegment, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'wf-zone wf-zone--soft wf-c-3 wf-c-md-4 wf-c-sm-12 wf-ds__list',
    role: 'complementary',
    '[attr.aria-label]': 'label()',
  },
  template: `
    <!-- Neu aufgebaut, wenn ein Wechsel abgelehnt wird: dann zeigt er wieder den Bereich. -->
    @for (k of [resetKey()]; track k) {
      <wf-segment
        [label]="'sources.area' | wfT"
        [options]="areas()"
        [value]="area()"
        (valueChange)="pick($event)"
      />
    }
    @for (item of items(); track item.id) {
      <button
        type="button"
        class="wf-ds__item"
        [class.wf-ds__item--on]="item.id === selected()"
        [attr.aria-current]="item.id === selected() ? 'true' : null"
        [attr.data-item]="item.id"
        (click)="open.emit(item.id)"
      >
        <span class="wf-ds__title">{{ item.title }}</span>
        <span class="wf-mono wf-muted">{{ item.sub }}</span>
      </button>
    } @empty {
      <p class="wf-hint">{{ empty() }}</p>
    }
    <button
      type="button"
      wfButton
      variant="text"
      size="sm"
      (click)="create.emit()"
      data-testid="ds-new"
    >
      {{ newLabel() }}
    </button>
    @if (hint(); as h) {
      <p class="wf-hint">{{ h }}</p>
    }
  `,
})
export class WfDsList {
  private readonly i18n = inject(WfI18n);
  readonly area = input.required<SourceArea>();
  readonly label = input.required<string>();
  readonly items = input.required<readonly SourceListItem[]>();
  readonly selected = input<string | null>(null);
  readonly empty = input.required<string>();
  readonly newLabel = input.required<string>();
  readonly hint = input<string | null>(null);
  readonly open = output<string>();
  readonly create = output<void>();
  /** Wechsel des Bereichs; kann abgelehnt werden (ungespeicherte Änderungen). */
  readonly switchTo = input.required<(area: SourceArea) => Promise<void>>();
  protected readonly resetKey = signal(0);

  protected async pick(area: SourceArea): Promise<void> {
    await this.switchTo()(area);
    if (this.area() !== area) {
      this.resetKey.update((n) => n + 1);
    }
  }

  protected readonly areas = computed<WfSegmentOption<SourceArea>[]>(() => [
    { value: 'verbindungen', label: this.i18n.t('sources.area.connections') },
    { value: 'modelle', label: this.i18n.t('sources.area.models') },
    { value: 'trigger', label: this.i18n.t('sources.area.triggers') },
  ]);
}

/** «vor 2 Tagen» und Ähnliches für das Protokoll. */
export function relativeWhen(i18n: WfI18n, at: string, now = Date.now()): string {
  const minutes = Math.floor((now - new Date(at).getTime()) / 60_000);
  if (!(minutes >= 1)) {
    return i18n.t('sources.when.now');
  }
  if (minutes < 60) {
    return i18n.t('sources.when.minutes', { n: minutes });
  }
  const hours = Math.floor(minutes / 60);
  return hours < 24
    ? i18n.t('sources.when.hours', { n: hours })
    : i18n.t('sources.when.days', { n: Math.floor(hours / 24) });
}

/** Klartext einer Protokollzeile, ohne Geheimnisse (die stehen nie im Protokoll). */
export function auditText(i18n: WfI18n, e: AuditEntry, now = Date.now()): string {
  const verb = e.action.split('.').at(-1) ?? e.action;
  const key = `sources.audit.${verb}`;
  const action =
    i18n.tDynamic(key) !== key
      ? i18n.tDynamic(key)
      : i18n.t('sources.audit.other', { action: e.action });
  const changed = Array.isArray(e.detail['changed'])
    ? (e.detail['changed'] as unknown[]).filter((c) => typeof c === 'string')
    : [];
  const text = i18n.t('sources.audit.entry', {
    action,
    actor: e.actor.name || e.actor.id,
    when: relativeWhen(i18n, e.at, now),
  });
  return changed.length > 0
    ? text + i18n.t('sources.audit.changed', { fields: changed.join(', ') })
    : text;
}

/** Aufklappbares Protokoll mit den letzten 5 Einträgen zu einem Objekt. */
@Component({
  selector: 'wf-ds-audit',
  imports: [WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <details class="wf-ds__audit" data-testid="ds-audit">
      <summary>{{ 'sources.audit' | wfT }}</summary>
      <ul>
        @for (e of entries(); track $index) {
          <li [title]="time(e.at)">{{ text(e) }}</li>
        } @empty {
          <li class="wf-hint">{{ 'sources.audit.none' | wfT }}</li>
        }
      </ul>
    </details>
  `,
})
export class WfDsAudit {
  private readonly api = inject(WorkflowApi);
  private readonly i18n = inject(WfI18n);
  readonly type = input.required<'connection' | 'model' | 'trigger'>();
  readonly objectId = input.required<string>();
  /** Erhöhen lädt neu (nach Speichern, Ersetzen …). */
  readonly reload = input(0);
  protected readonly entries = signal<readonly AuditEntry[]>([]);

  constructor() {
    effect(() => {
      const type = this.type();
      const id = this.objectId();
      this.reload();
      untracked(() => void this.load(type, id));
    });
  }

  private async load(type: string, id: string): Promise<void> {
    try {
      this.entries.set(
        (await firstValueFrom(this.api.dataSources.audit({ type, id, limit: 5 }))).entries,
      );
    } catch {
      this.entries.set([]);
    }
  }

  protected text(e: AuditEntry): string {
    return auditText(this.i18n, e);
  }

  protected time(at: string): string {
    return formatDateTime(at);
  }
}

/**
 * Gemeinsames der drei Formulare: Entwurf mit «ungespeichert», Meldungen, Feldfehler,
 * Rückfrage beim Wechsel und die Auswahl in der Adresse.
 */
@Directive()
export abstract class WfSourceEditorBase<D> {
  protected readonly api = inject(WorkflowApi);
  protected readonly i18n = inject(WfI18n);
  protected readonly dialogs = inject(WfDialogService);
  protected readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  /** Gewählte ID aus der Adresse (die Seite reicht sie durch). */
  readonly selectedId = input<string | null>(null);
  abstract readonly area: SourceArea;

  protected abstract empty(): D;
  protected readonly draft = signal<D>(this.empty());
  private readonly savedJson = signal<string>(JSON.stringify(this.empty()));
  protected readonly editing = signal(false);
  protected readonly isNew = signal(false);
  protected readonly busy = signal(false);
  protected readonly message = signal<{ tone: 'ok' | 'error'; text: string } | null>(null);
  protected readonly errors = signal<Readonly<Record<string, string>>>({});
  protected readonly auditReload = signal(0);

  protected readonly dirty = computed(
    () => this.editing() && this.comparable(this.draft()) !== this.savedJson(),
  );

  /** Was für «ungespeichert» zählt; Eingaben wie Geheimnisse lassen sich ausnehmen. */
  protected comparable(d: D): string {
    return JSON.stringify(d);
  }

  constructor() {
    effect(() => {
      const id = this.selectedId();
      untracked(() => void this.select(id));
    });
  }

  /** Lädt den Eintrag und setzt ihn mit `markSaved` als Entwurf. */
  protected abstract fetch(id: string): Promise<void>;
  protected abstract idOf(d: D): string;

  private async select(id: string | null): Promise<void> {
    this.errors.set({});
    if (!id) {
      if (!this.isNew()) {
        this.editing.set(false);
      }
      return;
    }
    if (id !== this.idOf(this.draft())) {
      this.message.set(null);
    }
    try {
      await this.fetch(id);
      this.isNew.set(false);
    } catch (error) {
      this.showError(error);
    }
  }

  hasUnsavedChanges(): boolean {
    return this.dirty();
  }

  protected markSaved(d: D): void {
    this.draft.set(d);
    this.savedJson.set(this.comparable(d));
    this.editing.set(true);
  }

  protected patch(change: Partial<D>): void {
    this.message.set(null);
    this.draft.update((d) => ({ ...d, ...change }));
  }

  protected err(key: string): string | null {
    return this.errors()[key] ?? null;
  }

  /** Prüfungen im Client: Schlüssel → Text; `true`, wenn alles stimmt. */
  protected check(errors: FormErrors): boolean {
    const texts: Record<string, string> = {};
    for (const [field, key] of Object.entries(errors)) {
      texts[field] = this.i18n.tDynamic(key);
    }
    this.errors.set(texts);
    if (Object.keys(texts).length > 0) {
      this.message.set({ tone: 'error', text: this.i18n.t('error.fieldsTitle') });
      return false;
    }
    return true;
  }

  /** Fehler der Engine: Feldfehler ans Feld (über `mapField`), sonst als Banner. */
  protected showError(
    error: unknown,
    mapField: (field: string) => string = (f) => f,
    bannerFields: readonly string[] = [],
  ): void {
    const e = WfApiError.from(error);
    const fields = e.fieldErrors();
    const texts: Record<string, string> = {};
    const banner: string[] = [];
    for (const [field, messages] of Object.entries(fields)) {
      const text = messages.join(' ');
      if (bannerFields.includes(field)) {
        banner.push(text);
      } else {
        texts[mapField(field)] = text;
      }
    }
    this.errors.set(texts);
    if (banner.length > 0) {
      this.message.set({ tone: 'error', text: banner.join(' ') });
    } else if (Object.keys(texts).length > 0) {
      this.message.set({ tone: 'error', text: this.i18n.t('error.fieldsTitle') });
    } else {
      const key = `error.${e.code}`;
      const known = this.i18n.tDynamic(key);
      this.message.set({
        tone: 'error',
        text: e.message || (known !== key ? known : this.i18n.t('error.unknown')),
      });
    }
  }

  protected async confirmLeave(): Promise<boolean> {
    return (
      !this.dirty() || wfConfirmDiscard(this.dialogs, this.i18n, this.i18n.t('sources.switch.text'))
    );
  }

  protected navigate(id: string | null, area: SourceArea = this.area): void {
    void this.router.navigate([], {
      queryParams: { bereich: area, id },
      queryParamsHandling: 'replace',
      preserveFragment: false,
      replaceUrl: area === this.area,
      relativeTo: this.route,
    });
  }

  protected readonly switchAreaFn = (area: SourceArea): Promise<void> => this.switchArea(area);

  protected async switchArea(area: SourceArea): Promise<void> {
    if (area !== this.area && (await this.confirmLeave())) {
      this.editing.set(false);
      this.navigate(null, area);
    }
  }

  /** Link in einen anderen Bereich (z. B. von der Verbindung zum Datenmodell). */
  protected async goTo(area: SourceArea, id: string): Promise<void> {
    if (await this.confirmLeave()) {
      this.editing.set(false);
      this.isNew.set(false);
      this.navigate(id, area);
    }
  }

  protected async open(id: string): Promise<void> {
    if ((id !== this.selectedId() || this.isNew()) && (await this.confirmLeave())) {
      this.isNew.set(false);
      this.navigate(id);
    }
  }

  protected async startNew(): Promise<void> {
    if (await this.confirmLeave()) {
      this.isNew.set(true);
      this.errors.set({});
      this.message.set(null);
      this.markSaved(this.empty());
      this.navigate(null);
    }
  }

  /** Rückfrage vor dem Löschen. */
  protected confirmDelete(id: string): Promise<boolean> {
    return this.dialogs.confirm({
      title: this.i18n.t('sources.delete.title', { id }),
      text: this.i18n.t('sources.delete.text'),
      cancelValue: false,
      actions: [
        { value: false, label: this.i18n.t('action.cancel') },
        { value: true, label: this.i18n.t('action.delete'), variant: 'danger' },
      ],
    });
  }

  abstract save(): Promise<boolean>;
}
