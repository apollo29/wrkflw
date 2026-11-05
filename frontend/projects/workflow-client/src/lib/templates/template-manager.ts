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
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom, map } from 'rxjs';
import { WfApiError } from '../api/api-error';
import type { DefinitionSummary, TemplateSummary, TemplateType, Usage } from '../api/models';
import { WorkflowApi } from '../api/workflow-api';
import { contextVariables } from '../editor/model/context-vars';
import { fromDefinition } from '../editor/model/definition-mapping';
import { WfHtmlEditor } from '../editor/html/html-editor';
import { previewHtml } from '../editor/html/email-extensions';
import { WfI18n, WfTranslatePipe } from '../i18n/i18n';
import { wfBeforeUnload, wfConfirmDiscard, WfUnsavedChanges } from '../shell/unsaved-changes';
import { WfBanner } from '../ui/banner';
import { WfButton } from '../ui/button';
import { WfDialogService } from '../ui/dialog';
import { WfField } from '../ui/field';
import { WfPill } from '../ui/pill';
import { WfSegment, WfSegmentOption } from '../ui/segment';

/** Wie im Backend (`TemplateController`). */
export const TEMPLATE_ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/;

interface Draft {
  readonly id: string;
  readonly name: string;
  readonly subject: string;
  readonly body: string;
}

interface UsageRow extends Usage {
  readonly name: string;
  readonly link: readonly (string | number)[];
}

const EMPTY: Draft = { id: '', name: '', subject: '', body: '' };

/** Reiter «Vorlagen» (9.11): Liste je Art, Formular mit HTML-Editor, Vorschau und Verwendung. */
@Component({
  selector: 'wf-template-manager',
  imports: [
    RouterLink,
    WfBanner,
    WfButton,
    WfField,
    WfHtmlEditor,
    WfPill,
    WfSegment,
    WfTranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-g12 wf-tpl' },
  template: `
    <aside
      class="wf-zone wf-zone--soft wf-c-3 wf-c-md-4 wf-c-sm-12 wf-tpl__list"
      [attr.aria-label]="'templates.label' | wfT"
    >
      <!-- Neu aufgebaut, wenn ein Wechsel abgelehnt wird: dann zeigt er wieder die Art. -->
      @for (k of [typeReset()]; track k) {
        <wf-segment
          [label]="'templates.type' | wfT"
          [options]="typeOptions()"
          [value]="type()"
          (valueChange)="setType($event)"
        />
      }
      @for (t of templates(); track t.id) {
        <button
          type="button"
          class="wf-tpl__item"
          [class.wf-tpl__item--on]="t.id === selectedId()"
          [attr.aria-current]="t.id === selectedId() ? 'true' : null"
          (click)="open(t.id)"
          [attr.data-template]="t.id"
        >
          <span class="wf-tpl__name">{{ t.name }}</span>
          <span class="wf-mono wf-muted">{{ t.id }}</span>
        </button>
      } @empty {
        <p class="wf-hint">{{ 'templates.empty' | wfT }}</p>
      }
      <button
        type="button"
        wfButton
        variant="text"
        size="sm"
        (click)="create()"
        data-testid="templates-new"
      >
        {{ 'templates.new' | wfT }}
      </button>
    </aside>

    <section
      class="wf-zone wf-c-9 wf-c-md-8 wf-c-sm-12 wf-tpl__form"
      [attr.aria-label]="'templates.formLabel' | wfT: { id: draft().id }"
    >
      @if (message(); as m) {
        <wf-banner [tone]="m.tone" data-testid="templates-message">{{ m.text }}</wf-banner>
      }
      @if (!editing()) {
        <p class="wf-muted">{{ 'templates.none' | wfT }}</p>
      } @else {
        @if (dirty()) {
          <wf-pill kind="timer" [dot]="true" data-testid="templates-dirty">{{
            'state.unsaved' | wfT
          }}</wf-pill>
        }
        <div class="wf-g12">
          <wf-field
            class="wf-c-6 wf-c-sm-12"
            [label]="'templates.id' | wfT"
            [hint]="isNew() ? null : ('templates.id.fixed' | wfT)"
            [error]="idError()"
          >
            <input
              class="wf-inp wf-mono"
              [value]="draft().id"
              [readonly]="!isNew()"
              [placeholder]="'templates.id.placeholder' | wfT"
              (input)="patch({ id: $any($event.target).value })"
              data-testid="templates-id"
            />
          </wf-field>
          <wf-field class="wf-c-6 wf-c-sm-12" [label]="'templates.name' | wfT">
            <input
              class="wf-inp"
              [value]="draft().name"
              (input)="patch({ name: $any($event.target).value })"
              data-testid="templates-name"
            />
          </wf-field>
          @if (type() === 'email') {
            <wf-field class="wf-c-12" [label]="'templates.subject' | wfT">
              <input
                class="wf-inp"
                [value]="draft().subject"
                (input)="patch({ subject: $any($event.target).value })"
                data-testid="templates-subject"
              />
            </wf-field>
          }
        </div>
        <div class="wf-ed-stack">
          <span class="wf-field-label">{{
            (type() === 'email' ? 'templates.body' : 'templates.bodyPage') | wfT
          }}</span>
          <wf-html-editor
            [label]="(type() === 'email' ? 'templates.body' : 'templates.bodyPage') | wfT"
            [value]="draft().body"
            (valueChange)="patch({ body: $event })"
            [placeholders]="placeholders()"
            [sampleValues]="samples()"
          />
          <p class="wf-hint">{{ 'templates.hint' | wfT }}</p>
          @if (type() === 'email' && draft().subject) {
            <p class="wf-hint" data-testid="templates-subject-preview">
              {{ 'templates.previewSubject' | wfT: { subject: subjectPreview() } }}
            </p>
          }
        </div>

        <div class="wf-tpl__usage" data-testid="templates-usage">
          @if (usageLoading()) {
            <span class="wf-hint">{{ 'templates.usage.loading' | wfT }}</span>
          } @else if (usage().length > 0) {
            <span>{{ 'templates.usage' | wfT: { n: usage().length } }}</span>
            <ul>
              @for (u of usage(); track $index) {
                <li>
                  <a [routerLink]="u.link">{{ u.name }}</a> ·
                  <span class="wf-mono">v{{ u.version }}</span> ·
                  <span class="wf-mono">{{ u.step }}</span>
                </li>
              }
            </ul>
          } @else {
            <span class="wf-hint">{{ 'templates.usage.none' | wfT }}</span>
          }
        </div>

        <div class="wf-ed-row wf-tpl__actions">
          <span class="wf-ed-spacer"></span>
          @if (!isNew()) {
            <button
              type="button"
              wfButton
              variant="danger"
              (click)="remove()"
              data-testid="templates-delete"
            >
              {{ 'action.delete' | wfT }}
            </button>
          }
          <button
            type="button"
            wfButton
            variant="primary"
            [busy]="busy()"
            (click)="save()"
            data-testid="templates-save"
          >
            {{ 'action.save' | wfT }}
          </button>
        </div>
      }
    </section>
  `,
})
export class WfTemplateManager implements WfUnsavedChanges {
  private readonly api = inject(WorkflowApi);
  private readonly i18n = inject(WfI18n);
  private readonly dialogs = inject(WfDialogService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  /** Art und Auswahl stehen in der Adresse (`?type=page&id=…`). */
  private readonly params = toSignal(
    this.route.queryParamMap.pipe(map((p) => ({ type: p.get('type'), id: p.get('id') }))),
    {
      initialValue: { type: null, id: null },
    },
  );
  protected readonly type = computed<TemplateType>(() =>
    this.params().type === 'page' ? 'page' : 'email',
  );
  protected readonly typeReset = signal(0);
  protected readonly selectedId = computed(() => this.params().id);

  protected readonly templates = signal<readonly TemplateSummary[]>([]);
  protected readonly draft = signal<Draft>(EMPTY);
  private readonly saved = signal<Draft | null>(null);
  protected readonly editing = signal(false);
  protected readonly isNew = signal(false);
  protected readonly busy = signal(false);
  protected readonly message = signal<{ tone: 'ok' | 'error'; text: string } | null>(null);
  protected readonly idError = signal<string | null>(null);
  protected readonly usage = signal<readonly UsageRow[]>([]);
  protected readonly usageLoading = signal(false);
  protected readonly placeholders = signal<readonly string[]>([]);
  protected readonly samples = signal<Readonly<Record<string, string>>>({});
  private definitions: readonly DefinitionSummary[] | null = null;

  protected readonly dirty = computed(() => {
    const saved = this.saved() ?? EMPTY;
    const d = this.draft();
    return (
      this.editing() &&
      (d.id !== saved.id ||
        d.name !== saved.name ||
        d.subject !== saved.subject ||
        d.body !== saved.body)
    );
  });

  protected readonly typeOptions = computed<WfSegmentOption<TemplateType>[]>(() => [
    { value: 'email', label: this.i18n.t('templates.type.email') },
    { value: 'page', label: this.i18n.t('templates.type.page') },
  ]);

  protected readonly subjectPreview = computed(() =>
    previewHtml(this.draft().subject, this.samples(), (name) =>
      this.i18n.t('html.sample', { name }),
    ).replace(
      /&(amp|lt|gt|quot|#39);/g,
      (m) => ({ '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#39;': "'" })[m] ?? m,
    ),
  );

  constructor() {
    effect(() => {
      const type = this.type();
      untracked(() => void this.loadList(type));
    });
    effect(() => {
      const id = this.selectedId();
      untracked(() => void this.load(id));
    });
    wfBeforeUnload(() => this.dirty());
  }

  hasUnsavedChanges(): boolean {
    return this.dirty();
  }

  private async loadList(type: TemplateType): Promise<void> {
    try {
      this.templates.set((await firstValueFrom(this.api.templates.list(type))).templates);
    } catch (error) {
      this.message.set({ tone: 'error', text: this.errorText(error) });
    }
  }

  private async load(id: string | null): Promise<void> {
    this.idError.set(null);
    if (!id) {
      if (!this.isNew()) {
        this.editing.set(false);
      }
      return;
    }
    if (id !== this.draft().id) {
      this.message.set(null);
    }
    try {
      const t = await firstValueFrom(this.api.templates.get(id));
      const draft = { id: t.id, name: t.name, subject: t.subject, body: t.body };
      this.draft.set(draft);
      this.saved.set(draft);
      this.isNew.set(false);
      this.editing.set(true);
      void this.loadUsage(t.id);
    } catch (error) {
      this.message.set({ tone: 'error', text: this.errorText(error) });
    }
  }

  /** Verwendung mit Ablaufnamen; Platzhalter-Vorschläge aus den verwendenden Abläufen (9.14). */
  private async loadUsage(id: string): Promise<void> {
    this.usageLoading.set(true);
    try {
      const [usage, definitions] = await Promise.all([
        firstValueFrom(this.api.templates.usage(id)).then((r) => r.usage),
        this.definitions
          ? Promise.resolve(this.definitions)
          : firstValueFrom(this.api.definitions.list()).then((r) => r.definitions),
      ]);
      this.definitions = definitions;
      const byId = new Map(definitions.map((d) => [d.id, d]));
      this.usage.set(
        usage.map((u) => {
          const d = byId.get(u.definitionId);
          const latest = d?.version === u.version;
          return {
            ...u,
            name: d?.name ?? u.definitionId,
            link: latest
              ? ['..', 'workflows', u.definitionId]
              : ['..', 'workflows', u.definitionId, 'v', u.version],
          };
        }),
      );
      const versions = [
        ...new Map(usage.map((u) => [`${u.definitionId}|${u.version}`, u])).values(),
      ];
      const names = new Set<string>();
      const samples: Record<string, string> = {};
      for (const u of versions) {
        try {
          const loaded = await firstValueFrom(
            this.api.definitions.version(u.definitionId, u.version),
          );
          const model = fromDefinition({ ...loaded.definition, id: loaded.id });
          contextVariables(model).forEach((v) => names.add(v.name));
          model.inputs.forEach((i) => {
            if (i.beispiel) {
              samples[i.name] = i.beispiel;
            }
          });
        } catch {
          // Ohne Definition bleibt die Verwendung sichtbar, nur ohne Vorschläge.
        }
      }
      this.placeholders.set([...names]);
      this.samples.set(samples);
    } catch {
      this.usage.set([]);
    } finally {
      this.usageLoading.set(false);
    }
  }

  protected patch(change: Partial<Draft>): void {
    this.message.set(null);
    if (change.id !== undefined) {
      this.idError.set(null);
    }
    this.draft.update((d) => ({ ...d, ...change }));
  }

  /** Wechsel mit offenen Änderungen fragt nach (E-08). */
  private async confirmLeave(): Promise<boolean> {
    if (!this.dirty()) {
      return true;
    }
    return wfConfirmDiscard(this.dialogs, this.i18n, this.i18n.t('templates.switch.text'));
  }

  protected async open(id: string): Promise<void> {
    if (id === this.selectedId() && !this.isNew()) {
      return;
    }
    if (await this.confirmLeave()) {
      this.isNew.set(false);
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { type: this.type(), id },
        replaceUrl: true,
      });
    }
  }

  protected async setType(type: TemplateType): Promise<void> {
    if (type === this.type() || !(await this.confirmLeave())) {
      // Abgelehnt: Umschalter zeigt wieder die aktuelle Art.
      this.typeReset.update((n) => n + 1);
      return;
    }
    this.isNew.set(false);
    this.editing.set(false);
    this.saved.set(null);
    void this.router.navigate([], { relativeTo: this.route, queryParams: { type, id: null } });
  }

  protected async create(): Promise<void> {
    if (!(await this.confirmLeave())) {
      return;
    }
    this.isNew.set(true);
    this.editing.set(true);
    this.draft.set(EMPTY);
    this.saved.set(EMPTY);
    this.usage.set([]);
    this.placeholders.set([]);
    this.samples.set({});
    this.message.set(null);
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { type: this.type(), id: null },
      replaceUrl: true,
    });
  }

  async save(): Promise<boolean> {
    const d = this.draft();
    const id = d.id.trim();
    if (id === '') {
      this.idError.set(this.i18n.t('templates.idMissing'));
      return false;
    }
    if (!TEMPLATE_ID_PATTERN.test(id)) {
      this.idError.set(this.i18n.t('templates.idInvalid'));
      return false;
    }
    this.busy.set(true);
    try {
      const saved = await firstValueFrom(
        this.api.templates.save(id, {
          name: d.name.trim() || id,
          type: this.type(),
          subject: this.type() === 'email' ? d.subject : '',
          body: d.body,
        }),
      );
      const draft = { id: saved.id, name: saved.name, subject: saved.subject, body: saved.body };
      this.draft.set(draft);
      this.saved.set(draft);
      const wasNew = this.isNew();
      this.isNew.set(false);
      await this.loadList(this.type());
      this.message.set({ tone: 'ok', text: this.i18n.t('templates.saved', { id: saved.id }) });
      if (wasNew) {
        void this.router.navigate([], {
          relativeTo: this.route,
          queryParams: { type: this.type(), id: saved.id },
          replaceUrl: true,
        });
      }
      return true;
    } catch (error) {
      const e = WfApiError.from(error);
      const field = e.fieldErrors()['id']?.[0];
      if (field) {
        this.idError.set(field);
      } else {
        this.message.set({ tone: 'error', text: this.errorText(e) });
      }
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  /** Löschen mit Rückfrage, die die Anzahl Verwendungen nennt (E-43). */
  protected async remove(): Promise<void> {
    const id = this.draft().id;
    const n = this.usage().length;
    const ok = await this.dialogs.confirm({
      title: this.i18n.t('templates.delete.title', { id }),
      text:
        n > 0
          ? this.i18n.t('templates.delete.textUsed', { n })
          : this.i18n.t('templates.delete.text'),
      cancelValue: false,
      actions: [
        { value: false, label: this.i18n.t('action.cancel') },
        { value: true, label: this.i18n.t('action.delete'), variant: 'danger' },
      ],
    });
    if (!ok) {
      return;
    }
    try {
      await firstValueFrom(this.api.templates.delete(id));
      this.saved.set(null);
      this.editing.set(false);
      await this.loadList(this.type());
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { type: this.type(), id: null },
        replaceUrl: true,
      });
      this.message.set({ tone: 'ok', text: this.i18n.t('templates.deleted', { id }) });
    } catch (error) {
      this.message.set({ tone: 'error', text: this.errorText(error) });
    }
  }

  private errorText(error: unknown): string {
    const e = WfApiError.from(error);
    const key = `error.${e.code}`;
    const known = this.i18n.tDynamic(key);
    return known !== key ? known : e.message || this.i18n.t('error.unknown');
  }
}
