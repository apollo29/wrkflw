import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  signal,
  untracked,
} from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { DefinitionSummary, ModelInfo, Trigger } from '../api/models';
import { fromDefinition } from '../editor/model/definition-mapping';
import { WfTranslatePipe } from '../i18n/i18n';
import { WfBanner } from '../ui/banner';
import { WfButton } from '../ui/button';
import { WfField } from '../ui/field';
import { WfPill } from '../ui/pill';
import {
  ConditionDraft,
  needsValue,
  OPERATOR_KEYS,
  OPERATORS,
  toTrigger,
  TriggerDraft,
  triggerDraft,
  validateTrigger,
} from './data-source-forms';
import { SourceListItem, WfDsAudit, WfDsList, WfSourceEditorBase } from './source-parts';

interface InputRow {
  readonly name: string;
  readonly label: string;
  readonly required: boolean;
}

/** Bereich «Trigger» (Board «Trigger», F-144, F-145). */
@Component({
  selector: 'wf-trigger-editor',
  imports: [WfBanner, WfButton, WfDsAudit, WfDsList, WfField, WfPill, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-g12 wf-ds' },
  template: `
    <wf-ds-list
      area="trigger"
      [label]="'sources.area.triggers' | wfT"
      [items]="items()"
      [selected]="isNew() ? null : selectedId()"
      [empty]="'sources.trigger.empty' | wfT"
      [newLabel]="'sources.trigger.new' | wfT"
      (open)="open($event)"
      (create)="startNew()"
      [switchTo]="switchAreaFn"
    />
    <section
      class="wf-zone wf-c-9 wf-c-md-8 wf-c-sm-12 wf-ds__form"
      [attr.aria-label]="'sources.trigger.label' | wfT: { id: draft().id }"
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
            class="wf-c-6 wf-c-sm-12"
            [label]="'sources.id' | wfT"
            [hint]="'sources.id.fixed' | wfT"
            [error]="err('id')"
          >
            <input
              class="wf-inp wf-mono"
              [value]="draft().id"
              [readonly]="!isNew()"
              (input)="patch({ id: $any($event.target).value })"
              data-testid="trigger-id"
            />
          </wf-field>
          <wf-field class="wf-c-6 wf-c-sm-12" [label]="'sources.name' | wfT">
            <input
              class="wf-inp"
              [value]="draft().name"
              (input)="patch({ name: $any($event.target).value })"
              data-testid="trigger-name"
            />
          </wf-field>
          <wf-field
            class="wf-c-6 wf-c-sm-12"
            [label]="'sources.trigger.modelLabel' | wfT"
            [hint]="'sources.trigger.model.hint' | wfT"
            [error]="err('model')"
          >
            <select
              class="wf-inp"
              (change)="setModel($any($event.target).value)"
              data-testid="trigger-model"
            >
              <option value="" [selected]="draft().model === ''">
                {{ 'sources.trigger.choose' | wfT }}
              </option>
              @for (m of searchable(); track m.name) {
                <option [value]="m.name" [selected]="m.name === draft().model">
                  {{ m.label }} ({{ m.name }})
                </option>
              }
            </select>
          </wf-field>
          <wf-field
            class="wf-c-6 wf-c-sm-12"
            [label]="'sources.trigger.workflowLabel' | wfT"
            [hint]="'sources.trigger.workflow.hint' | wfT"
            [error]="err('definitionId')"
          >
            <select
              class="wf-inp"
              (change)="patch({ definitionId: $any($event.target).value })"
              data-testid="trigger-workflow"
            >
              <option value="" [selected]="draft().definitionId === ''">
                {{ 'sources.trigger.choose' | wfT }}
              </option>
              @for (d of activeWorkflows(); track d.id) {
                <option [value]="d.id" [selected]="d.id === draft().definitionId">
                  {{ d.name }} ({{ d.id }})
                </option>
              }
            </select>
          </wf-field>
        </div>

        <div class="wf-ds__block">
          <div class="wf-ds__head">
            <span class="wf-field-label">{{ 'sources.trigger.conditions' | wfT }}</span>
            <button
              type="button"
              wfButton
              variant="text"
              size="sm"
              [disabled]="!model()"
              (click)="addCondition()"
              data-testid="trigger-condition-add"
            >
              {{ 'sources.trigger.conditionAdd' | wfT }}
            </button>
          </div>
          @if (draft().conditions.length > 0) {
            <div class="wf-ds__box" data-testid="trigger-conditions">
              @for (c of draft().conditions; track $index; let i = $index) {
                <div class="wf-g12 wf-ds__brow">
                  <select
                    class="wf-inp wf-c-4 wf-c-sm-12"
                    [attr.aria-label]="'sources.trigger.conditionField' | wfT"
                    (change)="setCondition(i, { field: $any($event.target).value })"
                  >
                    @for (f of fields(); track f.name) {
                      <option [value]="f.name" [selected]="f.name === c.field">
                        {{ f.label || f.name }}
                      </option>
                    }
                  </select>
                  <select
                    class="wf-inp wf-c-3 wf-c-sm-5"
                    [attr.aria-label]="'sources.trigger.conditionOperator' | wfT"
                    (change)="setCondition(i, { operator: $any($event.target).value })"
                  >
                    @for (op of operators; track op) {
                      <option [value]="op" [selected]="op === c.operator">
                        {{ opKey(op) | wfT }}
                      </option>
                    }
                  </select>
                  @if (hasValue(c)) {
                    @if (typeOf(c) === 'bool') {
                      <select
                        class="wf-inp wf-c-4 wf-c-sm-5"
                        [attr.aria-label]="'sources.trigger.conditionValue' | wfT"
                        (change)="setCondition(i, { value: $any($event.target).value })"
                      >
                        <option value="true" [selected]="c.value === 'true'">
                          {{ 'sources.trigger.yes' | wfT }}
                        </option>
                        <option value="false" [selected]="c.value !== 'true'">
                          {{ 'sources.trigger.no' | wfT }}
                        </option>
                      </select>
                    } @else {
                      <input
                        class="wf-inp wf-c-4 wf-c-sm-5"
                        [type]="
                          typeOf(c) === 'number' ? 'number' : typeOf(c) === 'date' ? 'date' : 'text'
                        "
                        [attr.aria-label]="'sources.trigger.conditionValue' | wfT"
                        [value]="c.value"
                        (input)="setCondition(i, { value: $any($event.target).value })"
                      />
                    }
                  } @else {
                    <span class="wf-c-4 wf-c-sm-5"></span>
                  }
                  <button
                    type="button"
                    wfButton
                    variant="icon"
                    class="wf-c-1 wf-c-sm-2"
                    [attr.aria-label]="'sources.trigger.conditionRemove' | wfT"
                    (click)="removeCondition(i)"
                  >
                    ×
                  </button>
                </div>
              }
            </div>
          } @else {
            <p class="wf-hint">{{ 'sources.trigger.conditions.none' | wfT }}</p>
          }
          @if (err('conditions'); as e) {
            <span class="wf-ds__error" role="alert">{{ e }}</span>
          }
          <p class="wf-hint">{{ 'sources.trigger.conditions.hint' | wfT }}</p>
        </div>

        <div class="wf-ds__block">
          <span class="wf-field-label">{{ 'sources.trigger.inputs' | wfT }}</span>
          @if (!draft().definitionId) {
            <p class="wf-hint">{{ 'sources.trigger.inputs.choose' | wfT }}</p>
          } @else if (inputs().length === 0) {
            <p class="wf-hint">{{ 'sources.trigger.inputs.none' | wfT }}</p>
          } @else {
            <div class="wf-ds__box" data-testid="trigger-inputs">
              @for (input of inputs(); track input.name) {
                <div class="wf-g12 wf-ds__brow" [attr.data-input]="input.name">
                  <span class="wf-c-5 wf-c-sm-12">
                    <span class="wf-mono">{{ input.name }}</span> · {{ input.label }}
                    @if (input.required) {
                      <span class="wf-muted">{{ 'sources.trigger.required' | wfT }}</span>
                    }
                  </span>
                  <span class="wf-c-1 wf-hide-sm wf-muted wf-ds__eq" aria-hidden="true">←</span>
                  <select
                    class="wf-inp wf-c-6 wf-c-sm-12"
                    [attr.aria-label]="'sources.trigger.inputField' | wfT: { name: input.name }"
                    [attr.aria-invalid]="err('inputs.' + input.name) ? 'true' : null"
                    (change)="setInput(input.name, $any($event.target).value)"
                  >
                    <option value="" [selected]="!draft().inputs[input.name]">
                      {{ 'sources.trigger.inputNone' | wfT }}
                    </option>
                    @for (f of fields(); track f.name) {
                      <option [value]="f.name" [selected]="f.name === draft().inputs[input.name]">
                        {{ f.label || f.name }} ({{ f.name }})
                      </option>
                    }
                  </select>
                  @if (err('inputs.' + input.name); as e) {
                    <span class="wf-c-12 wf-ds__error" role="alert">{{ e }}</span>
                  }
                </div>
              }
            </div>
          }
        </div>

        <div class="wf-g12">
          <wf-field
            class="wf-c-4 wf-c-sm-12"
            [label]="'sources.trigger.every' | wfT"
            [hint]="'sources.trigger.interval' | wfT"
            [error]="err('intervalMinutes')"
          >
            <span class="wf-ds__row">
              <input
                class="wf-inp wf-ds__num"
                type="number"
                min="1"
                [value]="draft().every"
                (input)="patch({ every: +$any($event.target).value })"
                data-testid="trigger-every"
              />
              <select
                class="wf-inp"
                [attr.aria-label]="'sources.trigger.unit' | wfT"
                (change)="patch({ unit: $any($event.target).value })"
                data-testid="trigger-unit"
              >
                <option value="min" [selected]="draft().unit === 'min'">
                  {{ 'sources.trigger.unit.min' | wfT }}
                </option>
                <option value="h" [selected]="draft().unit === 'h'">
                  {{ 'sources.trigger.unit.h' | wfT }}
                </option>
              </select>
            </span>
          </wf-field>
          <wf-field
            class="wf-c-4 wf-c-sm-12"
            [label]="'sources.trigger.lockDays' | wfT"
            [hint]="'sources.trigger.lockDays.hint' | wfT"
            [error]="err('lockDays')"
          >
            <input
              class="wf-inp wf-ds__num"
              type="number"
              min="1"
              [value]="draft().lockDays"
              (input)="patch({ lockDays: +$any($event.target).value })"
              data-testid="trigger-lock"
            />
          </wf-field>
          <div class="wf-c-4 wf-c-sm-12 wf-ds__secret">
            <span class="wf-field-label">{{ 'sources.trigger.status' | wfT }}</span>
            <label class="wf-ds__switch">
              <input
                type="checkbox"
                role="switch"
                [checked]="draft().active"
                (change)="patch({ active: $any($event.target).checked })"
                data-testid="trigger-active"
              />
              {{ 'sources.trigger.active' | wfT }}
            </label>
            <p class="wf-hint">{{ 'sources.trigger.active.hint' | wfT }}</p>
          </div>
        </div>

        @if (!isNew()) {
          <div class="wf-ds__row wf-ds__row--wrap">
            <button
              type="button"
              wfButton
              [busy]="checking()"
              (click)="checkNow()"
              data-testid="trigger-check"
            >
              {{ 'sources.trigger.check' | wfT }}
            </button>
            @if (matches() !== null) {
              <span data-testid="trigger-check-result">{{
                'sources.trigger.checkResult' | wfT: { n: matches()! }
              }}</span>
            }
          </div>
        }

        <div class="wf-ds__foot">
          <span class="wf-ed-spacer"></span>
          @if (!isNew()) {
            <button
              type="button"
              wfButton
              variant="danger"
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
          <wf-ds-audit type="trigger" [objectId]="draft().id" [reload]="auditReload()" />
        }
      }
    </section>
  `,
})
export class WfTriggerEditor extends WfSourceEditorBase<TriggerDraft> {
  readonly area = 'trigger' as const;
  protected readonly operators = OPERATORS;
  private readonly triggers = signal<readonly Trigger[]>([]);
  /** Alle Datenmodelle (Host-App und REST) aus dem Katalog; Trigger brauchen «Suchen». */
  private readonly models = signal<readonly ModelInfo[]>([]);
  private readonly workflows = signal<readonly DefinitionSummary[]>([]);
  protected readonly inputs = signal<readonly InputRow[]>([]);
  protected readonly checking = signal(false);
  protected readonly matches = signal<number | null>(null);

  protected readonly items = computed<SourceListItem[]>(() =>
    this.triggers().map((t) => ({
      id: t.id,
      title: t.name,
      sub: this.i18n.t('sources.trigger.via', { model: t.model, workflow: t.definitionId }),
    })),
  );
  protected readonly searchable = computed(() => this.models().filter((m) => m.operations.find));
  /** Nur aktive Abläufe, dazu der schon gewählte (falls er inzwischen inaktiv ist). */
  protected readonly activeWorkflows = computed(() =>
    this.workflows().filter((d) => d.status === 'active' || d.id === this.draft().definitionId),
  );
  protected readonly model = computed(
    () => this.models().find((m) => m.name === this.draft().model) ?? null,
  );
  protected readonly fields = computed(() => this.model()?.fields ?? []);

  constructor() {
    super();
    void this.loadLists();
    // Startkontext aus den Eingaben der neuesten Version des Ablaufs.
    effect(() => {
      const id = this.draft().definitionId;
      const summary = this.workflows().find((d) => d.id === id);
      untracked(() => void this.loadInputs(summary ?? null));
    });
  }

  protected empty(): TriggerDraft {
    return triggerDraft(null);
  }

  protected idOf(d: TriggerDraft): string {
    return d.id;
  }

  private async loadLists(): Promise<void> {
    try {
      const [triggers, models, workflows] = await Promise.all([
        firstValueFrom(this.api.dataSources.triggers()),
        firstValueFrom(this.api.catalogs.dataModels()),
        firstValueFrom(this.api.definitions.list()),
      ]);
      this.triggers.set(triggers.triggers);
      this.models.set(models.models);
      this.workflows.set(workflows.definitions);
    } catch (error) {
      this.showError(error);
    }
  }

  private loadedInputsFor = '';

  private async loadInputs(summary: DefinitionSummary | null): Promise<void> {
    const key = summary ? `${summary.id}|${summary.version}` : '';
    if (key === this.loadedInputsFor) {
      return;
    }
    this.loadedInputsFor = key;
    if (!summary) {
      this.inputs.set([]);
      return;
    }
    try {
      const loaded = await firstValueFrom(
        this.api.definitions.version(summary.id, summary.version),
      );
      const model = fromDefinition({ ...loaded.definition, id: loaded.id });
      if (this.loadedInputsFor === key) {
        this.inputs.set(
          model.inputs.map((i) => ({ name: i.name, label: i.label, required: i.required })),
        );
      }
    } catch {
      this.inputs.set([]);
    }
  }

  protected async fetch(id: string): Promise<void> {
    const t = await firstValueFrom(this.api.dataSources.trigger(id));
    this.matches.set(null);
    this.markSaved(triggerDraft(t));
  }

  protected opKey(op: (typeof OPERATORS)[number]): string {
    return OPERATOR_KEYS[op];
  }

  protected hasValue(c: ConditionDraft): boolean {
    return needsValue(c.operator);
  }

  protected typeOf(c: ConditionDraft): string {
    return this.fields().find((f) => f.name === c.field)?.type ?? 'text';
  }

  protected setModel(model: string): void {
    // Bedingungen und Zuordnung gehören zum Modell.
    this.patch({ model, conditions: [], inputs: {} });
  }

  protected addCondition(): void {
    const field = this.fields()[0]?.name ?? '';
    this.patch({ conditions: [...this.draft().conditions, { field, operator: '=', value: '' }] });
  }

  protected setCondition(i: number, change: Partial<ConditionDraft>): void {
    this.patch({
      conditions: this.draft().conditions.map((c, n) => (n === i ? { ...c, ...change } : c)),
    });
  }

  protected removeCondition(i: number): void {
    this.patch({ conditions: this.draft().conditions.filter((_, n) => n !== i) });
  }

  protected setInput(name: string, field: string): void {
    this.patch({ inputs: { ...this.draft().inputs, [name]: field } });
  }

  protected async checkNow(): Promise<void> {
    this.checking.set(true);
    try {
      this.matches.set(
        (await firstValueFrom(this.api.dataSources.checkTrigger(this.draft().id))).matches,
      );
    } catch (error) {
      this.showError(error);
    } finally {
      this.checking.set(false);
    }
  }

  async save(): Promise<boolean> {
    const d = this.draft();
    if (!this.check(validateTrigger(d, this.inputs()))) {
      return false;
    }
    this.busy.set(true);
    try {
      const body = toTrigger(d, this.fields());
      const wasNew = this.isNew();
      const { id, ...rest } = body;
      const saved = wasNew
        ? await firstValueFrom(this.api.dataSources.createTrigger(body))
        : await firstValueFrom(this.api.dataSources.updateTrigger(id, rest));
      this.isNew.set(false);
      this.markSaved(triggerDraft(saved));
      this.errors.set({});
      await this.loadLists();
      this.message.set({ tone: 'ok', text: this.i18n.t('sources.saved', { id: saved.id }) });
      this.auditReload.update((n) => n + 1);
      if (wasNew) {
        this.navigate(saved.id);
      }
      return true;
    } catch (error) {
      this.showError(error);
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  protected async remove(): Promise<void> {
    const id = this.draft().id;
    if (!(await this.confirmDelete(id))) {
      return;
    }
    try {
      await firstValueFrom(this.api.dataSources.deleteTrigger(id));
      this.editing.set(false);
      await this.loadLists();
      this.navigate(null);
      this.message.set({ tone: 'ok', text: this.i18n.t('sources.deleted', { id }) });
    } catch (error) {
      this.showError(error);
    }
  }
}
