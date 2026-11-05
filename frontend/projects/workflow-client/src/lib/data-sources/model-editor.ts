import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { Connection, ModelUsage, RestModel, SampleResult } from '../api/models';
import { WfTranslatePipe } from '../i18n/i18n';
import { WfBanner } from '../ui/banner';
import { WfButton } from '../ui/button';
import { WfField } from '../ui/field';
import { WfPill } from '../ui/pill';
import {
  canWrite,
  FIELD_TYPES,
  FieldDraft,
  fieldFromSample,
  ModelDraft,
  modelDraft,
  OPERATION_KINDS,
  OPERATION_METHODS,
  OperationKind,
  SampleNode,
  sampleNodes,
  toRestModel,
  validateModel,
} from './data-source-forms';
import { SourceListItem, WfDsAudit, WfDsList, WfSourceEditorBase } from './source-parts';

interface Sample {
  readonly status: number;
  readonly ms: number;
  readonly nodes: readonly SampleNode[];
  readonly paths: SampleResult['paths'];
}

/** Bereich «Datenmodelle» (Board «Datenmodell», F-140 bis F-143). */
@Component({
  selector: 'wf-model-editor',
  imports: [WfBanner, WfButton, WfDsAudit, WfDsList, WfField, WfPill, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-g12 wf-ds' },
  template: `
    <wf-ds-list
      area="modelle"
      [label]="'sources.area.models' | wfT"
      [items]="items()"
      [selected]="isNew() ? null : selectedId()"
      [empty]="'sources.model.empty' | wfT"
      [newLabel]="'sources.model.new' | wfT"
      [hint]="'sources.model.hostHint' | wfT"
      (open)="open($event)"
      (create)="startNew()"
      [switchTo]="switchAreaFn"
    />
    <section
      class="wf-zone wf-c-9 wf-c-md-8 wf-c-sm-12 wf-ds__form"
      [attr.aria-label]="'sources.model.label' | wfT: { id: draft().name }"
    >
      @if (message(); as m) {
        <wf-banner [tone]="m.tone" data-testid="ds-message">{{ m.text }}</wf-banner>
      }
      @if (!editing()) {
        <p class="wf-muted">{{ 'sources.none' | wfT }}</p>
      } @else {
        @if (dirty()) {
          <wf-pill kind="timer" [dot]="true" data-testid="ds-dirty">{{
            'state.unsaved' | wfT
          }}</wf-pill>
        }
        <div class="wf-g12">
          <wf-field
            class="wf-c-4 wf-c-sm-12"
            [label]="'sources.name' | wfT"
            [hint]="'sources.id.fixed' | wfT"
            [error]="err('name')"
          >
            <input
              class="wf-inp wf-mono"
              [value]="draft().name"
              [readonly]="!isNew()"
              (input)="patch({ name: $any($event.target).value })"
              data-testid="model-name"
            />
          </wf-field>
          <wf-field
            class="wf-c-4 wf-c-sm-12"
            [label]="'sources.model.title' | wfT"
            [error]="err('label')"
          >
            <input
              class="wf-inp"
              [value]="draft().label"
              (input)="patch({ label: $any($event.target).value })"
              data-testid="model-label"
            />
          </wf-field>
          <wf-field
            class="wf-c-4 wf-c-sm-12"
            [label]="'sources.model.connectionLabel' | wfT"
            [error]="err('connection')"
          >
            <select
              class="wf-inp"
              (change)="patch({ connection: $any($event.target).value })"
              data-testid="model-connection"
            >
              <option value="" [selected]="draft().connection === ''">
                {{ 'sources.model.connectionChoose' | wfT }}
              </option>
              @for (c of connections(); track c.id) {
                <option [value]="c.id" [selected]="c.id === draft().connection">
                  {{ c.name }} ({{ c.id }})
                </option>
              }
            </select>
          </wf-field>
        </div>

        <div class="wf-ds__block">
          <h2 class="wf-lbl">{{ 'sources.model.operations' | wfT }}</h2>
          <div class="wf-ds__box">
            @for (kind of kinds; track kind) {
              <div class="wf-g12 wf-ds__brow" [attr.data-op]="kind">
                <span
                  class="wf-c-2 wf-c-sm-12 wf-ds__op"
                  [class.wf-muted]="!draft().operations[kind].path"
                  >{{ 'sources.model.op.' + kind | wfT }}</span
                >
                <select
                  class="wf-inp wf-mono wf-c-2 wf-c-sm-4"
                  [attr.aria-label]="
                    'sources.model.opMethod' | wfT: { op: ('sources.model.op.' + kind | wfT) }
                  "
                  (change)="setOp(kind, { method: $any($event.target).value })"
                >
                  @for (m of methods[kind]; track m) {
                    <option [value]="m" [selected]="m === draft().operations[kind].method">
                      {{ m }}
                    </option>
                  }
                </select>
                <input
                  class="wf-inp wf-mono wf-c-7 wf-c-sm-6"
                  [attr.aria-label]="
                    'sources.model.opPath' | wfT: { op: ('sources.model.op.' + kind | wfT) }
                  "
                  [placeholder]="'sources.model.opEmpty' | wfT"
                  [value]="draft().operations[kind].path"
                  [attr.aria-invalid]="err('operations.' + kind) ? 'true' : null"
                  (input)="setOp(kind, { path: $any($event.target).value })"
                />
                <span class="wf-c-1 wf-c-sm-2">
                  @if (!draft().operations[kind].path) {
                    <wf-pill [dashed]="true">{{ 'sources.model.opOff' | wfT }}</wf-pill>
                  }
                </span>
                @if (err('operations.' + kind); as e) {
                  <span class="wf-c-12 wf-ds__error" role="alert">{{ e }}</span>
                }
              </div>
            }
          </div>
          <p class="wf-hint">{{ 'sources.model.opHint' | wfT }}</p>
        </div>

        <div class="wf-g12">
          <wf-field
            class="wf-c-4 wf-c-sm-12"
            [label]="'sources.model.listPath' | wfT"
            [hint]="'sources.model.listPath.hint' | wfT"
            [error]="err('listPath')"
          >
            <input
              class="wf-inp wf-mono"
              [value]="draft().listPath"
              (input)="patch({ listPath: $any($event.target).value })"
              data-testid="model-list"
            />
          </wf-field>
          <div class="wf-c-8 wf-c-sm-12 wf-ds__block">
            <div class="wf-ds__head">
              <span class="wf-field-label">{{ 'sources.model.subset' | wfT }}</span>
              <button
                type="button"
                wfButton
                variant="text"
                size="sm"
                (click)="addSubset()"
                data-testid="model-subset-add"
              >
                {{ 'sources.model.subsetAdd' | wfT }}
              </button>
            </div>
            @if (draft().subset.length > 0) {
              <div class="wf-ds__box">
                @for (s of draft().subset; track $index; let i = $index) {
                  <div class="wf-g12 wf-ds__brow">
                    <input
                      class="wf-inp wf-mono wf-c-5"
                      [attr.aria-label]="'sources.model.subsetKey' | wfT"
                      [value]="s.key"
                      (input)="setSubset(i, { key: $any($event.target).value })"
                    />
                    <span class="wf-c-1 wf-c-sm-1 wf-muted wf-ds__eq">=</span>
                    <input
                      class="wf-inp wf-mono wf-c-5"
                      [attr.aria-label]="'sources.model.subsetValue' | wfT"
                      [value]="s.value"
                      (input)="setSubset(i, { value: $any($event.target).value })"
                    />
                    <button
                      type="button"
                      wfButton
                      variant="icon"
                      class="wf-c-1 wf-c-sm-2"
                      [attr.aria-label]="'sources.model.subsetRemove' | wfT: { name: s.key }"
                      (click)="removeSubset(i)"
                    >
                      ×
                    </button>
                  </div>
                }
              </div>
            }
            <p class="wf-hint">{{ 'sources.model.subset.hint' | wfT }}</p>
          </div>
        </div>

        <div class="wf-ds__block">
          <h2 class="wf-lbl">{{ 'sources.model.sample' | wfT }}</h2>
          <div class="wf-ds__row wf-ds__row--wrap">
            <input
              class="wf-inp wf-mono wf-ds__sample-id"
              [attr.aria-label]="'sources.model.sampleId' | wfT"
              [value]="sampleId()"
              (input)="sampleId.set($any($event.target).value)"
              (keydown.enter)="fetchSample()"
              data-testid="model-sample-id"
            />
            <button
              type="button"
              wfButton
              [busy]="sampling()"
              [disabled]="!sampleId().trim() || !draft().connection"
              (click)="fetchSample()"
              data-testid="model-sample"
            >
              {{ 'sources.model.sample' | wfT }}
            </button>
            @if (sample(); as s) {
              <wf-pill
                [kind]="s.status < 400 ? 'ok' : 'error'"
                [mono]="true"
                data-testid="model-sample-result"
                >{{ 'sources.model.sampleResult' | wfT: { status: s.status, ms: s.ms } }}</wf-pill
              >
            }
          </div>
          <p class="wf-hint">{{ 'sources.model.sample.hint' | wfT }}</p>
          @if (sample(); as s) {
            <ul class="wf-ds__tree" data-testid="model-sample-tree">
              @for (n of s.nodes; track n.path) {
                <li [style.padding-left.px]="n.depth * 16">
                  @switch (n.kind) {
                    @case ('group') {
                      <span class="wf-mono wf-muted">▾ {{ n.key }}</span>
                    }
                    @case ('list') {
                      <span class="wf-mono wf-muted">{{ n.key }}: [ ]</span>
                      <span class="wf-hint">{{
                        'sources.model.sampleList' | wfT: { n: n.value ?? 0 }
                      }}</span>
                    }
                    @default {
                      <button
                        type="button"
                        class="wf-ds__tv wf-mono"
                        [disabled]="taken(n.path)"
                        (click)="take(n)"
                        [attr.data-path]="n.path"
                      >
                        {{ n.key }}: {{ n.value }}
                      </button>
                      @if (taken(n.path)) {
                        <span class="wf-hint">{{ 'sources.model.sampleTaken' | wfT }}</span>
                      } @else {
                        <span class="wf-hint wf-ds__take" aria-hidden="true">{{
                          'sources.model.sampleTake' | wfT
                        }}</span>
                      }
                    }
                  }
                </li>
              }
            </ul>
          }
        </div>

        <div class="wf-ds__block">
          <div class="wf-ds__head">
            <h2 class="wf-lbl">{{ 'sources.model.fields' | wfT }}</h2>
            <button
              type="button"
              wfButton
              variant="text"
              size="sm"
              (click)="addField()"
              data-testid="model-field-add"
            >
              {{ 'sources.model.fieldAdd' | wfT }}
            </button>
          </div>
          <div class="wf-ds__box" data-testid="model-fields">
            <div class="wf-g12 wf-ds__brow wf-ds__brow--head wf-hide-sm">
              <span class="wf-lbl wf-c-3">{{ 'sources.name' | wfT }}</span>
              <span class="wf-lbl wf-c-3">{{ 'sources.model.fieldLabel' | wfT }}</span>
              <span class="wf-lbl wf-c-2">{{ 'sources.model.fieldType' | wfT }}</span>
              <span class="wf-lbl wf-c-2">{{ 'sources.model.fieldPath' | wfT }}</span>
              <span class="wf-lbl wf-c-1">{{ 'sources.model.fieldWritable' | wfT }}</span>
            </div>
            @for (f of draft().fields; track $index; let i = $index) {
              <div class="wf-g12 wf-ds__brow" [attr.data-field]="f.name">
                <input
                  class="wf-inp wf-mono wf-c-3 wf-c-sm-6"
                  [attr.aria-label]="'sources.name' | wfT"
                  [value]="f.name"
                  (input)="setField(i, { name: $any($event.target).value })"
                />
                <input
                  class="wf-inp wf-c-3 wf-c-sm-6"
                  [attr.aria-label]="'sources.model.fieldLabel' | wfT"
                  [value]="f.label"
                  (input)="setField(i, { label: $any($event.target).value })"
                />
                <select
                  class="wf-inp wf-c-2 wf-c-sm-4"
                  [attr.aria-label]="'sources.model.fieldType' | wfT"
                  (change)="setField(i, { type: $any($event.target).value })"
                >
                  @for (type of types; track type) {
                    <option [value]="type" [selected]="type === f.type">
                      {{ 'sources.type.' + type | wfT }}
                    </option>
                  }
                </select>
                <input
                  class="wf-inp wf-mono wf-c-2 wf-c-sm-4"
                  [attr.aria-label]="'sources.model.fieldPath' | wfT"
                  [value]="f.path"
                  (input)="setField(i, { path: $any($event.target).value })"
                />
                <span class="wf-c-1 wf-c-sm-2 wf-ds__check">
                  <input
                    type="checkbox"
                    [attr.aria-label]="'sources.model.fieldWritableFor' | wfT: { name: f.name }"
                    [checked]="f.writable && writableOn()"
                    [disabled]="!writableOn()"
                    (change)="setField(i, { writable: $any($event.target).checked })"
                  />
                </span>
                <button
                  type="button"
                  wfButton
                  variant="icon"
                  class="wf-c-1 wf-c-sm-2"
                  [disabled]="!!lockReason(f)"
                  [attr.title]="lockReason(f)"
                  [attr.aria-label]="
                    (lockReason(f) ? 'sources.removeLocked' : 'sources.removeItem')
                      | wfT: { name: f.name }
                  "
                  (click)="removeField(i)"
                >
                  ×
                </button>
                @if (err('fields.' + i); as e) {
                  <span class="wf-c-12 wf-ds__error" role="alert">{{ e }}</span>
                }
              </div>
            }
          </div>
          @if (err('fields') ?? err('idField'); as e) {
            <span class="wf-ds__error" role="alert">{{ e }}</span>
          }
          <p class="wf-hint">{{ 'sources.model.fields.hint' | wfT }}</p>
        </div>

        <div class="wf-ds__foot">
          <span class="wf-ds__usage" data-testid="ds-usage">
            @if (isNew()) {
            } @else if (usageCount() > 0) {
              @if (usageRows().length > 0) {
                <span class="wf-ds__usage-group">
                  <strong>{{ 'sources.model.usage' | wfT: { n: usageSteps() } }}</strong>
                  @for (u of usageRows(); track u.key; let last = $last) {
                    <span class="wf-mono">{{ u.key }}</span> ·
                    <span class="wf-mono">{{ u.steps }}</span
                    >{{ last ? '' : '; ' }}
                  }
                </span>
              }
              @if (usage()?.triggers?.length) {
                <span class="wf-ds__usage-group">
                  <strong>{{ 'sources.model.usageTriggers' | wfT }}</strong>
                  @for (t of usage()!.triggers; track t; let last = $last) {
                    <a
                      class="wf-mono"
                      href=""
                      (click)="$event.preventDefault(); goTo('trigger', t)"
                      >{{ t }}</a
                    >{{ last ? '' : ', ' }}
                  }
                </span>
              }
            } @else {
              <span class="wf-hint">{{ 'sources.model.usage.none' | wfT }}</span>
            }
          </span>
          <span class="wf-ed-spacer"></span>
          @if (!isNew()) {
            @if (usageCount() > 0) {
              <span class="wf-hint">{{ 'sources.deleteLocked' | wfT }}</span>
            }
            <button
              type="button"
              wfButton
              variant="danger"
              [disabled]="locked()"
              (click)="remove()"
              data-testid="ds-delete"
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
            data-testid="ds-save"
          >
            {{ 'action.save' | wfT }}
          </button>
        </div>
        @if (!isNew()) {
          <wf-ds-audit type="model" [objectId]="draft().name" [reload]="auditReload()" />
        }
      }
    </section>
  `,
})
export class WfModelEditor extends WfSourceEditorBase<ModelDraft> {
  readonly area = 'modelle' as const;
  protected readonly kinds = OPERATION_KINDS;
  protected readonly methods = OPERATION_METHODS;
  protected readonly types = FIELD_TYPES;
  private readonly models = signal<readonly RestModel[]>([]);
  protected readonly connections = signal<readonly Connection[]>([]);
  protected readonly items = computed<SourceListItem[]>(() =>
    this.models().map((m) => ({
      id: m.name,
      title: m.label,
      sub: this.i18n.t('sources.model.via', { name: m.name, connection: m.connection }),
    })),
  );
  protected readonly usage = signal<ModelUsage | null>(null);
  protected readonly sampleId = signal('');
  protected readonly sampling = signal(false);
  protected readonly sample = signal<Sample | null>(null);
  protected readonly writableOn = computed(() => canWrite(this.draft()));

  /** Verwendete Felder mit Ort, z. B. «antrag-pruefung v3 · daten_laden, speichern». */
  private readonly usedFields = computed(() => {
    const where = new Map<string, Map<string, string[]>>();
    for (const u of this.usage()?.definitions ?? []) {
      for (const field of u.fields) {
        const byDef = where.get(field) ?? new Map<string, string[]>();
        const key = `${u.definitionId} v${u.version}`;
        byDef.set(key, [...(byDef.get(key) ?? []), u.step]);
        where.set(field, byDef);
      }
    }
    return new Map(
      [...where].map(([field, byDef]) => [
        field,
        [...byDef].map(([k, steps]) => `${k} · ${steps.join(', ')}`).join('; '),
      ]),
    );
  });
  protected readonly usageRows = computed(() => {
    const rows = new Map<string, string[]>();
    for (const u of this.usage()?.definitions ?? []) {
      const key = `${u.definitionId} v${u.version}`;
      const steps = rows.get(key) ?? [];
      if (!steps.includes(u.step)) {
        steps.push(u.step);
      }
      rows.set(key, steps);
    }
    return [...rows].map(([key, steps]) => ({ key, steps: steps.join(', ') }));
  });
  protected readonly usageSteps = computed(
    () =>
      new Set(
        (this.usage()?.definitions ?? []).map((u) => `${u.definitionId}|${u.version}|${u.step}`),
      ).size,
  );
  protected readonly locked = computed(() => this.usageCount() > 0);
  protected readonly usageCount = computed(
    () => this.usageSteps() + (this.usage()?.triggers.length ?? 0),
  );

  constructor() {
    super();
    void this.loadLists();
  }

  protected empty(): ModelDraft {
    const d = modelDraft(null);
    d.fields.push({ name: 'id', label: 'ID', type: 'text', path: 'id', writable: false });
    return d;
  }

  protected idOf(d: ModelDraft): string {
    return d.name;
  }

  private async loadLists(): Promise<void> {
    try {
      const [models, connections] = await Promise.all([
        firstValueFrom(this.api.dataSources.models()),
        firstValueFrom(this.api.dataSources.connections()),
      ]);
      this.models.set(models.models);
      this.connections.set(connections.connections);
    } catch (error) {
      this.showError(error);
    }
  }

  protected async fetch(name: string): Promise<void> {
    const m = await firstValueFrom(this.api.dataSources.model(name));
    this.sample.set(null);
    this.markSaved(modelDraft(m));
    this.usage.set(null);
    try {
      this.usage.set(await firstValueFrom(this.api.dataSources.modelUsage(name)));
    } catch {
      // Ohne Verwendung bleibt alles offen; die Engine prüft ohnehin.
    }
  }

  protected lockReason(f: FieldDraft): string | null {
    if (!this.isNew() && f.name === this.draft().idField) {
      return this.i18n.t('sources.model.idFieldLocked');
    }
    const where = this.usedFields().get(f.name);
    return where ? this.i18n.t('sources.model.fieldUsed', { where }) : null;
  }

  protected taken(path: string): boolean {
    return this.draft().fields.some((f) => (f.path || f.name) === path);
  }

  private update(fn: (d: ModelDraft) => void): void {
    this.message.set(null);
    this.draft.update((d) => {
      const copy: ModelDraft = structuredClone(d);
      fn(copy);
      return copy;
    });
  }

  protected setOp(kind: OperationKind, change: Partial<{ method: string; path: string }>): void {
    this.update((d) => (d.operations[kind] = { ...d.operations[kind], ...change }));
  }

  protected addSubset(): void {
    this.update((d) => d.subset.push({ key: '', value: '' }));
  }

  protected setSubset(i: number, change: Partial<{ key: string; value: string }>): void {
    this.update((d) => (d.subset[i] = { ...d.subset[i]!, ...change }));
  }

  protected removeSubset(i: number): void {
    this.update((d) => d.subset.splice(i, 1));
  }

  protected addField(): void {
    this.update((d) =>
      d.fields.push({ name: '', label: '', type: 'text', path: '', writable: false }),
    );
  }

  protected setField(i: number, change: Partial<FieldDraft>): void {
    this.update((d) => {
      const before = d.fields[i]!;
      // Pfad folgt dem Namen, solange er nicht eigens gesetzt ist.
      const followPath =
        change.name !== undefined && (before.path === '' || before.path === before.name);
      d.fields[i] = { ...before, ...change, ...(followPath ? { path: change.name } : {}) };
      if (change.name !== undefined && before.name === d.idField) {
        d.idField = change.name;
      }
    });
  }

  protected removeField(i: number): void {
    this.update((d) => d.fields.splice(i, 1));
  }

  protected take(n: SampleNode): void {
    const info = this.sample()?.paths.find((p) => p.path === n.path) ?? {
      path: n.path,
      type: 'text',
      sample: null,
    };
    this.update((d) =>
      d.fields.push(
        fieldFromSample(
          info,
          d.fields.map((f) => f.name),
        ),
      ),
    );
  }

  /** «Beispiel abrufen» mit der noch ungespeicherten Beschreibung (F-139). */
  protected async fetchSample(): Promise<void> {
    const id = this.sampleId().trim();
    if (!id || !this.draft().connection) {
      return;
    }
    this.sampling.set(true);
    const start = performance.now();
    try {
      const result = await firstValueFrom(
        this.api.dataSources.sample(toRestModel(this.draft()), id),
      );
      this.sample.set({
        status: result.status,
        ms: Math.round(performance.now() - start),
        nodes: sampleNodes(result.tree),
        paths: result.paths,
      });
    } catch (error) {
      this.sample.set(null);
      this.showError(error);
    } finally {
      this.sampling.set(false);
    }
  }

  /** `fields.status` der Engine → Zeile des Felds. */
  private fieldKey = (field: string): string => {
    const m = /^fields\.(.+)$/.exec(field);
    if (!m) {
      return field;
    }
    const i = this.draft().fields.findIndex((f) => f.name.trim() === m[1]);
    return i >= 0 ? `fields.${i}` : 'fields';
  };

  async save(): Promise<boolean> {
    const d = this.draft();
    if (!this.check(validateModel(d))) {
      return false;
    }
    this.busy.set(true);
    try {
      const body = toRestModel(d);
      const wasNew = this.isNew();
      const saved = wasNew
        ? await firstValueFrom(this.api.dataSources.createModel(body))
        : await firstValueFrom(this.api.dataSources.updateModel(d.name, body));
      this.isNew.set(false);
      this.markSaved(modelDraft(saved));
      this.errors.set({});
      await this.loadLists();
      this.message.set({ tone: 'ok', text: this.i18n.t('sources.saved', { id: saved.name }) });
      this.auditReload.update((n) => n + 1);
      if (wasNew) {
        this.navigate(saved.name);
      }
      return true;
    } catch (error) {
      this.showError(error, this.fieldKey, ['model']);
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  protected async remove(): Promise<void> {
    const name = this.draft().name;
    if (!(await this.confirmDelete(name))) {
      return;
    }
    try {
      await firstValueFrom(this.api.dataSources.deleteModel(name));
      this.editing.set(false);
      await this.loadLists();
      this.navigate(null);
      this.message.set({ tone: 'ok', text: this.i18n.t('sources.deleted', { id: name }) });
    } catch (error) {
      this.showError(error, this.fieldKey, ['model']);
    }
  }
}
