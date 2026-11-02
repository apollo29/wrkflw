import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  signal,
  effect,
} from '@angular/core';
import { WfI18n, WfTranslatePipe } from '../../i18n/i18n';
import { WfButton } from '../../ui/button';
import { WfField } from '../../ui/field';
import { WfIcon } from '../../ui/icon';
import { WfPill } from '../../ui/pill';
import { STEP_KINDS, stepKindInfo, StepKind } from '../../ui/step-kinds';
import { EditorField, EditorStep } from '../model/definition-mapping';
import { KIND_ACTIONS, stepKind } from '../model/operations';
import { EditorStore } from '../state/editor-store';
import { WfActionFields } from './action-fields';
import { WfDataCheckForm, WfDataWriteForm } from './data-forms';
import { checkedOf, valueOf } from './dom';
import { WfTransitions } from './transitions';

type Visibility = 'default' | 'show' | 'hide';
type TimeUnit = 60 | 3600 | 86400;

const FIELD_TYPES = ['text', 'boolean', 'file', 'display'] as const;

/** Detail des gewählten Schritts (9.7). */
@Component({
  selector: 'wf-step-detail',
  imports: [
    WfActionFields,
    WfButton,
    WfDataCheckForm,
    WfDataWriteForm,
    WfField,
    WfIcon,
    WfPill,
    WfTransitions,
    WfTranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'wf-zone wf-ed-detail',
    role: 'region',
    '[attr.aria-label]': 'step()?.name ?? null',
  },
  template: `
    @if (step(); as s) {
      @let info = kindInfo();
      <div class="wf-ed-stack">
        <div class="wf-ed-row">
          <input
            class="wf-inp wf-inp--ghost wf-ed-detail__name"
            [attr.aria-label]="'editor.step.name' | wfT"
            [value]="nameDraft()"
            [readonly]="store.readOnly()"
            [attr.aria-invalid]="nameError() ? 'true' : null"
            (input)="onName($event)"
            (blur)="commitName()"
            (keydown.enter)="commitName()"
            data-testid="step-name"
          />
          <wf-pill [kind]="info.kind" [icon]="info.icon">{{ info.label | wfT }}</wf-pill>
        </div>
        @if (nameError(); as err) {
          <span class="wf-hint" style="color: var(--wfb-err-fg)" role="alert">{{ err }}</span>
        } @else {
          <span class="wf-hint">{{
            (isStart() ? 'editor.step.nameHintStart' : 'editor.step.nameHint')
              | wfT: { name: s.name }
          }}</span>
        }
      </div>

      @if (stepIssues().length > 0) {
        <ul
          class="wf-ed-issues"
          style="margin: 0; padding-left: 18px; color: var(--wfb-err-fg)"
          [attr.aria-label]="'editor.step.issues' | wfT"
          data-testid="step-issues"
        >
          @for (issue of stepIssues(); track $index) {
            <li [style.color]="issue.severity === 'hint' ? 'var(--wfb-text-muted)' : null">
              {{ issue.message }}
            </li>
          }
        </ul>
      }

      <div class="wf-g12">
        <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.step.kind' | wfT">
          <select
            class="wf-inp"
            [disabled]="store.readOnly()"
            (change)="setKind($event)"
            data-testid="step-kind"
          >
            @for (k of kinds; track k) {
              <option [value]="k" [selected]="k === kind()">{{ kindLabel(k) }}</option>
            }
          </select>
        </wf-field>
        <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.step.public' | wfT">
          <select class="wf-inp" [disabled]="store.readOnly()" (change)="setVisibility($event)">
            <option value="default" [selected]="visibility() === 'default'">
              {{ 'editor.step.public.default' | wfT }}
            </option>
            <option value="show" [selected]="visibility() === 'show'">
              {{ 'editor.step.public.show' | wfT }}
            </option>
            <option value="hide" [selected]="visibility() === 'hide'">
              {{ 'editor.step.public.hide' | wfT }}
            </option>
          </select>
        </wf-field>
      </div>

      @if (kind() === 'interactive' || visibility() === 'show') {
        <div class="wf-g12">
          <wf-field
            class="wf-c-6 wf-c-sm-12"
            [label]="'editor.step.template' | wfT"
            [hint]="'editor.step.template.hint' | wfT"
          >
            <select
              class="wf-inp"
              [disabled]="store.readOnly()"
              (change)="patch({ pageTemplateId: val($event) })"
            >
              <option value="">{{ 'editor.step.template.none' | wfT }}</option>
              @for (t of pageTemplates(); track t.id) {
                <option [value]="t.id" [selected]="t.id === s.pageTemplateId">
                  {{ t.name }} ({{ t.id }})
                </option>
              }
            </select>
          </wf-field>
          <wf-field
            class="wf-c-6 wf-c-sm-12"
            [label]="
              (kind() === 'interactive' ? 'editor.step.title' : 'editor.step.titlePublic') | wfT
            "
          >
            <input
              class="wf-inp"
              [value]="s.title"
              [readonly]="store.readOnly()"
              (input)="patch({ title: val($event) })"
            />
          </wf-field>
          <wf-field class="wf-c-12" [label]="'editor.step.description' | wfT">
            <textarea
              class="wf-inp"
              [value]="s.description"
              [readonly]="store.readOnly()"
              (input)="patch({ description: val($event) })"
            ></textarea>
          </wf-field>
        </div>
      }

      @switch (kind()) {
        @case ('interactive') {
          <div class="wf-ed-stack">
            <label class="wf-ed-label-inline">
              <input
                type="checkbox"
                class="wf-chk"
                [checked]="s.backAllowed"
                [disabled]="store.readOnly()"
                (change)="patch({ backAllowed: chk($event) })"
              />
              {{ 'editor.step.back' | wfT }}
            </label>
            <span class="wf-hint">{{ 'editor.step.back.hint' | wfT }}</span>
          </div>
          <section class="wf-ed-section">
            <div class="wf-ed-section__head">
              <h3>{{ 'editor.fields.title' | wfT }}</h3>
              @if (!store.readOnly()) {
                <button type="button" wfButton variant="text" size="sm" (click)="addField()">
                  {{ 'editor.fields.add' | wfT }}
                </button>
              }
            </div>
            @if (s.fields.length > 0) {
              <div class="wf-ed-list" data-testid="field-list">
                @for (f of s.fields; track $index; let i = $index) {
                  <div class="wf-ed-list__row wf-g12">
                    <input
                      class="wf-inp wf-mono wf-c-3 wf-c-sm-12"
                      [attr.aria-label]="'editor.fields.name' | wfT"
                      [value]="f.name"
                      [readonly]="store.readOnly()"
                      (input)="patchField(i, { name: val($event) })"
                    />
                    <input
                      class="wf-inp wf-c-5 wf-c-sm-12"
                      [attr.aria-label]="'editor.fields.label' | wfT"
                      [value]="f.label"
                      [readonly]="store.readOnly()"
                      (input)="patchField(i, { label: val($event) })"
                    />
                    <select
                      class="wf-inp wf-c-3 wf-c-sm-10"
                      [attr.aria-label]="'editor.fields.type' | wfT"
                      [disabled]="store.readOnly()"
                      (change)="patchField(i, { type: val($event) })"
                    >
                      @for (type of fieldTypes; track type) {
                        <option [value]="type" [selected]="type === f.type">
                          {{ fieldTypeLabel(type) }}
                        </option>
                      }
                    </select>
                    <span class="wf-c-1 wf-c-sm-2">
                      @if (!store.readOnly()) {
                        <button
                          type="button"
                          wfButton
                          variant="icon"
                          [attr.aria-label]="'editor.fields.remove' | wfT: { name: f.name }"
                          (click)="removeField(i)"
                        >
                          <wf-icon name="close" />
                        </button>
                      }
                    </span>
                    @if (f.type === 'file') {
                      <wf-field
                        class="wf-c-12"
                        [label]="'editor.fields.handler' | wfT"
                        [hint]="handlerHint(f)"
                      >
                        <select
                          class="wf-inp"
                          [disabled]="store.readOnly()"
                          (change)="patchField(i, { handler: val($event) || undefined })"
                        >
                          <option value="">{{ 'editor.fields.handler.none' | wfT }}</option>
                          @for (h of store.catalogs().uploadHandlers; track h.key) {
                            <option [value]="h.key" [selected]="h.key === f.handler">
                              {{ h.label }}
                            </option>
                          }
                        </select>
                      </wf-field>
                    }
                    @if (f.type === 'display') {
                      <span class="wf-c-12 wf-hint">{{
                        'editor.fields.display.hint' | wfT: { name: f.name }
                      }}</span>
                    }
                  </div>
                }
              </div>
            }
          </section>
        }
        @case ('timer') {
          <div class="wf-g12">
            <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.timer.wait' | wfT">
              <input
                type="number"
                min="0"
                class="wf-inp"
                [value]="timerAmount()"
                [readonly]="store.readOnly()"
                (input)="setTimer(+val($event), timerUnit())"
              />
            </wf-field>
            <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.timer.unit' | wfT">
              <select
                class="wf-inp"
                [disabled]="store.readOnly()"
                (change)="setTimer(timerAmount(), unitOf($event))"
              >
                <option value="60" [selected]="timerUnit() === 60">
                  {{ 'editor.timer.minutes' | wfT }}
                </option>
                <option value="3600" [selected]="timerUnit() === 3600">
                  {{ 'editor.timer.hours' | wfT }}
                </option>
                <option value="86400" [selected]="timerUnit() === 86400">
                  {{ 'editor.timer.days' | wfT }}
                </option>
              </select>
            </wf-field>
          </div>
        }
        @case ('workflow') {
          <div class="wf-g12">
            <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.workflow.target' | wfT">
              <select
                class="wf-inp"
                [disabled]="store.readOnly()"
                (change)="setConfig('workflowId', val($event))"
              >
                <option value="">{{ 'editor.workflow.none' | wfT }}</option>
                @for (d of otherWorkflows(); track d.id) {
                  <option [value]="d.id" [selected]="d.id === s.config['workflowId']">
                    {{ d.name }} ({{ d.id }})
                  </option>
                }
              </select>
            </wf-field>
            <div class="wf-c-6 wf-c-sm-12 wf-ed-stack" style="justify-content: flex-end">
              <label class="wf-ed-label-inline">
                <input
                  type="checkbox"
                  class="wf-chk"
                  [checked]="s.config['waitForCompletion'] === true"
                  [disabled]="store.readOnly()"
                  (change)="setConfig('waitForCompletion', chk($event))"
                />
                {{ 'editor.workflow.wait' | wfT }}
              </label>
              <span class="wf-hint">{{ 'editor.workflow.wait.hint' | wfT }}</span>
            </div>
          </div>
        }
        @case ('data-check') {
          <wf-data-check-form [step]="s" />
        }
        @case ('data-write') {
          <wf-data-write-form [step]="s" />
        }
        @default {
          <div class="wf-g12">
            <wf-field
              class="wf-c-6 wf-c-sm-12"
              [label]="'editor.action' | wfT"
              [hint]="actions().length === 0 ? ('editor.action.empty' | wfT) : null"
            >
              <select
                class="wf-inp"
                [disabled]="store.readOnly()"
                (change)="setAction($event)"
                data-testid="step-action"
              >
                <option value="">{{ 'editor.action.none' | wfT }}</option>
                @for (a of actions(); track a.key) {
                  <option [value]="a.key" [selected]="a.key === s.action">{{ a.label }}</option>
                }
              </select>
            </wf-field>
          </div>
          @if (s.action) {
            <wf-action-fields [step]="s" />
          }
        }
      }

      <hr />
      <wf-transitions [step]="s" />
    } @else {
      <div class="wf-ed-stack">
        <h3>{{ 'editor.detail.empty.title' | wfT }}</h3>
        <p class="wf-muted">{{ 'editor.detail.empty.text' | wfT }}</p>
      </div>
    }
  `,
})
export class WfStepDetail {
  protected readonly store = inject(EditorStore);
  private readonly i18n = inject(WfI18n);
  protected readonly step = this.store.selectedStep;
  protected readonly kinds = STEP_KINDS;
  protected readonly fieldTypes = FIELD_TYPES;
  protected val = valueOf;
  protected chk = checkedOf;

  protected readonly nameDraft = signal('');
  protected readonly nameError = signal<string | null>(null);

  protected readonly kind = computed<StepKind>(() => {
    const s = this.step();
    return s ? stepKind(s) : 'automatic';
  });
  protected readonly kindInfo = computed(() => stepKindInfo(this.kind()));
  protected readonly isStart = computed(() => this.step()?.name === this.store.model().startStep);
  protected readonly visibility = computed<Visibility>(() => {
    const v = this.step()?.publicVisible;
    return v === true ? 'show' : v === false ? 'hide' : 'default';
  });
  protected readonly stepIssues = computed(() =>
    this.store.issues().filter((i) => i.step !== null && i.step === this.step()?.name),
  );
  protected readonly pageTemplates = computed(() =>
    this.store.catalogs().templates.filter((t) => t.type === 'page'),
  );
  protected readonly otherWorkflows = computed(() =>
    this.store.catalogs().definitions.filter((d) => d.id !== this.store.model().id),
  );
  /** Ohne Aktionen, die eine eigene Karte haben (9.14). */
  protected readonly actions = computed(() =>
    this.store.catalogs().actions.filter((a) => !KIND_ACTIONS.includes(a.key)),
  );

  protected readonly timerUnit = computed<TimeUnit>(() => {
    const seconds = this.step()?.delaySeconds ?? 3600;
    return seconds > 0 && seconds % 86400 === 0
      ? 86400
      : seconds > 0 && seconds % 3600 === 0
        ? 3600
        : 60;
  });
  protected readonly timerAmount = computed(() =>
    Math.round((this.step()?.delaySeconds ?? 3600) / this.timerUnit()),
  );

  constructor() {
    effect(() => {
      this.nameDraft.set(this.step()?.name ?? '');
      this.nameError.set(null);
    });
  }

  protected kindLabel(kind: StepKind): string {
    return this.i18n.t(stepKindInfo(kind).label);
  }

  protected fieldTypeLabel(type: string): string {
    return this.i18n.tDynamic(`editor.fields.type.${type}`);
  }

  protected handlerHint(field: EditorField): string {
    const handlers = this.store.catalogs().uploadHandlers;
    if (handlers.length === 0) {
      return this.i18n.t('editor.fields.handler.empty');
    }
    const chosen = handlers.find((h) => h.key === field.handler);
    return chosen ? chosen.description : this.i18n.t('editor.fields.handler.missing');
  }

  protected onName(event: Event): void {
    this.nameDraft.set(valueOf(event));
    this.nameError.set(null);
  }

  /** Umbenennen beim Verlassen des Feldes; Fehler erscheinen am Feld (9.14). */
  protected commitName(): void {
    const step = this.step();
    if (!step || this.nameDraft() === step.name) {
      return;
    }
    const error = this.store.rename(step.name, this.nameDraft());
    this.nameError.set(
      error === null ? null : this.i18n.tDynamic(`editor.step.nameError.${error}`),
    );
  }

  protected patch(change: Partial<EditorStep>): void {
    const step = this.step();
    if (step) {
      this.store.updateStep(step.name, (s) => ({ ...s, ...change }));
    }
  }

  protected setKind(event: Event): void {
    const step = this.step();
    if (step) {
      this.store.setKind(step.name, valueOf(event) as StepKind);
    }
  }

  protected setVisibility(event: Event): void {
    const v = valueOf(event) as Visibility;
    this.patch({ publicVisible: v === 'show' ? true : v === 'hide' ? false : null });
  }

  protected setAction(event: Event): void {
    const action = valueOf(event);
    this.patch({ action: action === '' ? null : action, config: {} });
  }

  protected setConfig(name: string, value: string | boolean): void {
    const step = this.step();
    if (!step) {
      return;
    }
    const config = { ...step.config };
    if (value === '' || value === false) {
      delete config[name];
    } else {
      config[name] = value;
    }
    this.patch({ config });
  }

  protected unitOf(event: Event): TimeUnit {
    return Number(valueOf(event)) as TimeUnit;
  }

  protected setTimer(amount: number, unit: TimeUnit): void {
    this.patch({
      delaySeconds: Math.max(0, Math.round(Number.isFinite(amount) ? amount : 0)) * unit,
    });
  }

  /** «+ Feld» legt `feld` / «Feld» / Text an (E-23). */
  protected addField(): void {
    const step = this.step();
    if (!step) {
      return;
    }
    const taken = new Set(step.fields.map((f) => f.name));
    let name = 'feld';
    for (let n = 2; taken.has(name); n++) {
      name = `feld_${n}`;
    }
    this.patch({
      fields: [
        ...step.fields,
        { name, label: this.i18n.t('editor.fields.new.label'), type: 'text' },
      ],
    });
  }

  protected patchField(index: number, change: Partial<EditorField>): void {
    const step = this.step();
    if (step) {
      this.patch({ fields: step.fields.map((f, i) => (i === index ? { ...f, ...change } : f)) });
    }
  }

  protected removeField(index: number): void {
    const step = this.step();
    if (step) {
      this.patch({ fields: step.fields.filter((_, i) => i !== index) });
    }
  }
}
