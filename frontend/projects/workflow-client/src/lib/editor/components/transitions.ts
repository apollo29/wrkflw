import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input, signal } from '@angular/core';
import { WfI18n, WfTranslatePipe } from '../../i18n/i18n';
import { WfButton } from '../../ui/button';
import { WfIcon } from '../../ui/icon';
import { WfSegment, WfSegmentOption } from '../../ui/segment';
import {
  compileCondition,
  ConditionOp,
  EditorStep,
  EditorTransition,
} from '../model/definition-mapping';
import { EditorStore } from '../state/editor-store';
import { valueOf } from './dom';

const OPS: readonly ConditionOp[] = ['==', '!=', '>', '<', '>=', '<='];

/** Ein «Sonst»-Übergang: Assistent ohne Feld oder Ausdruck leer bzw. `true`. */
export function isElseTransition(t: EditorTransition): boolean {
  return t.mode === 'assistant'
    ? t.condition.field.trim() === ''
    : t.raw.trim() === '' || t.raw.trim() === 'true';
}

/** Übergänge eines Schritts (9.8, E-26 bis E-29). */
@Component({
  selector: 'wf-transitions',
  imports: [NgTemplateOutlet, WfButton, WfIcon, WfSegment, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-ed-trans' },
  template: `
    <div class="wf-ed-section__head">
      <h3>
        {{
          (interactive() ? 'editor.transitions.titleInteractive' : 'editor.transitions.title') | wfT
        }}
      </h3>
      @if (!store.readOnly()) {
        <button
          type="button"
          wfButton
          variant="text"
          size="sm"
          (click)="add()"
          data-testid="add-transition"
        >
          {{ 'editor.transitions.add' | wfT }}
        </button>
      }
    </div>
    <p class="wf-hint">
      {{ (interactive() ? 'editor.transitions.hintInteractive' : 'editor.transitions.hint') | wfT }}
    </p>
    <datalist [id]="listId">
      @for (v of store.variables(); track v.name) {
        <option [value]="v.name"></option>
      }
    </datalist>

    @for (t of step().transitions; track $index; let i = $index) {
      @if (isElse(t) && !expanded().has(i)) {
        <div class="wf-ed-else" data-testid="transition-else">
          @if (interactive()) {
            <ng-container [ngTemplateOutlet]="eventFields" [ngTemplateOutletContext]="{ t, i }" />
          }
          <span>{{ 'editor.transitions.elseGoto' | wfT }}</span>
          <ng-container [ngTemplateOutlet]="target" [ngTemplateOutletContext]="{ t, i }" />
          <span class="wf-ed-flow__else">{{ 'editor.transitions.unconditional' | wfT }}</span>
          <span class="wf-ed-spacer"></span>
          @if (!store.readOnly()) {
            <button type="button" wfButton variant="text" size="sm" (click)="openAssistant(i)">
              {{ 'editor.transitions.addCondition' | wfT }}
            </button>
            <button
              type="button"
              wfButton
              variant="icon"
              size="sm"
              [attr.aria-label]="'editor.transitions.remove' | wfT"
              (click)="remove(i)"
            >
              <wf-icon name="close" />
            </button>
          }
        </div>
      } @else {
        <div class="wf-ed-card" data-testid="transition-card">
          <div class="wf-ed-row">
            <span class="wf-mono wf-muted">{{ 'editor.transitions.id' | wfT: { n: i + 1 } }}</span>
            <span class="wf-ed-spacer"></span>
            <wf-segment
              [label]="'editor.transitions.mode' | wfT"
              [options]="modeOptions()"
              [value]="t.mode"
              (valueChange)="setMode(i, $event)"
            />
            @if (!store.readOnly()) {
              <button
                type="button"
                wfButton
                variant="icon"
                size="sm"
                [attr.aria-label]="'editor.transitions.remove' | wfT"
                (click)="remove(i)"
              >
                <wf-icon name="close" />
              </button>
            }
          </div>
          @if (interactive()) {
            <div class="wf-ed-row">
              <ng-container [ngTemplateOutlet]="eventFields" [ngTemplateOutletContext]="{ t, i }" />
            </div>
          }
          @if (t.mode === 'assistant') {
            <div class="wf-ed-stack">
              <span class="wf-field-label">{{ 'editor.transitions.if' | wfT }}</span>
              <div class="wf-g12">
                <input
                  class="wf-inp wf-mono wf-c-4 wf-c-sm-12"
                  [attr.list]="listId"
                  [attr.aria-label]="'editor.transitions.field' | wfT"
                  [value]="t.condition.field"
                  [readonly]="store.readOnly()"
                  (input)="setCondition(i, { field: val($event) })"
                  data-testid="condition-field"
                />
                <select
                  class="wf-inp wf-c-3 wf-c-sm-12"
                  [attr.aria-label]="'editor.transitions.op' | wfT"
                  [disabled]="store.readOnly()"
                  (change)="setCondition(i, { op: opOf($event) })"
                >
                  @for (op of ops; track op) {
                    <option [value]="op" [selected]="op === t.condition.op">
                      {{ opLabel(op) }}
                    </option>
                  }
                </select>
                <input
                  class="wf-inp wf-c-5 wf-c-sm-12"
                  [attr.aria-label]="'editor.transitions.value' | wfT"
                  [value]="t.condition.value"
                  [readonly]="store.readOnly()"
                  (input)="setCondition(i, { value: val($event) })"
                  data-testid="condition-value"
                />
              </div>
            </div>
          } @else {
            <label class="wf-ed-stack">
              <span class="wf-field-label">{{ 'editor.transitions.expression' | wfT }}</span>
              <input
                class="wf-inp wf-mono"
                placeholder="context['x'] == 'y'"
                [value]="t.raw"
                [readonly]="store.readOnly()"
                (input)="setRaw(i, val($event))"
              />
            </label>
          }
          <div class="wf-ed-row">
            <span>{{ 'editor.transitions.goto' | wfT }}</span>
            <ng-container [ngTemplateOutlet]="target" [ngTemplateOutletContext]="{ t, i }" />
          </div>
          @if (t.mode === 'assistant') {
            <div class="wf-ed-stack">
              <span class="wf-hint">{{ 'editor.transitions.expression' | wfT }}</span>
              <output class="wf-ed-expr" data-testid="condition-expression">{{
                expression(t)
              }}</output>
              <span class="wf-hint">{{ 'editor.transitions.valueHint' | wfT }}</span>
            </div>
          }
        </div>
      }
    } @empty {
      <p class="wf-hint">{{ 'editor.transitions.empty' | wfT }}</p>
    }

    @if (interactive() && buttons().length > 0) {
      <div class="wf-ed-row">
        <span class="wf-hint">{{ 'editor.transitions.preview' | wfT }}</span>
        @for (b of buttons(); track b) {
          <span class="wf-btn wf-btn--primary wf-btn--sm wf-ed-preview-btn" aria-hidden="true">{{
            b
          }}</span>
        }
      </div>
    }

    <ng-template #eventFields let-t="t" let-i="i">
      <label class="wf-ed-label-inline">
        {{ 'editor.transitions.on' | wfT }}
        <input
          class="wf-inp wf-mono wf-ed-inline-inp"
          style="width: 110px"
          placeholder="submit"
          [attr.aria-label]="'editor.transitions.event' | wfT"
          [value]="t.event ?? ''"
          [readonly]="store.readOnly()"
          (input)="setEvent(i, val($event))"
        />
      </label>
      <label class="wf-ed-label-inline">
        {{ 'editor.transitions.button' | wfT }}
        <input
          class="wf-inp wf-ed-inline-inp"
          style="width: 150px"
          [value]="t.label"
          [readonly]="store.readOnly()"
          (input)="setLabel(i, val($event))"
        />
      </label>
    </ng-template>
    <ng-template #target let-t="t" let-i="i">
      <select
        class="wf-inp wf-ed-inline-inp"
        style="max-width: 260px"
        [attr.aria-label]="'editor.transitions.target' | wfT"
        [disabled]="store.readOnly()"
        (change)="setTarget(i, val($event))"
        data-testid="transition-target"
      >
        @if (!targets().includes(t.to)) {
          <option value="" selected>{{ 'editor.transitions.target.none' | wfT }}</option>
        }
        @for (name of targets(); track name) {
          <option [value]="name" [selected]="name === t.to">{{ name }}</option>
        }
      </select>
    </ng-template>
  `,
})
export class WfTransitions {
  readonly step = input.required<EditorStep>();
  protected readonly store = inject(EditorStore);
  private readonly i18n = inject(WfI18n);
  private static nextId = 0;
  protected readonly listId = `wf-ctx-vars-${++WfTransitions.nextId}`;
  protected readonly ops = OPS;
  protected val = valueOf;
  protected isElse = isElseTransition;

  /** Leere Bedingungskarten, die per «Bedingung…» geöffnet wurden (bleiben Karten, 9.14). */
  private readonly opened = signal<Readonly<Record<string, readonly number[]>>>({});
  protected readonly expanded = computed(() => new Set(this.opened()[this.step().name] ?? []));

  protected readonly interactive = computed(() => this.step().type === 'interactive');
  protected readonly targets = computed(() => this.store.steps().map((s) => s.name));
  protected readonly modeOptions = computed<WfSegmentOption<'assistant' | 'raw'>[]>(() => [
    { value: 'assistant', label: this.i18n.t('editor.transitions.mode.assistant') },
    { value: 'raw', label: this.i18n.t('editor.transitions.mode.raw') },
  ]);

  /** Ein Knopf je Ereignis; die erste Beschriftung gilt (E-29). */
  protected readonly buttons = computed(() => {
    const seen = new Map<string, string>();
    for (const t of this.step().transitions) {
      const event = (t.event ?? '').trim();
      if (event !== '' && !seen.has(event)) {
        seen.set(event, t.label.trim() || event);
      }
    }
    return [...seen.values()];
  });

  protected opLabel(op: ConditionOp): string {
    return this.i18n.tDynamic(`editor.transitions.op.${op}`);
  }

  protected opOf(event: Event): ConditionOp {
    return valueOf(event) as ConditionOp;
  }

  protected expression(t: EditorTransition): string {
    return compileCondition(t.condition);
  }

  private patch(index: number, change: (t: EditorTransition) => EditorTransition): void {
    this.store.updateStep(this.step().name, (step) => ({
      ...step,
      transitions: step.transitions.map((t, i) => (i === index ? change(t) : t)),
    }));
  }

  /** Neue Übergänge an interaktiven Schritten bekommen `submit` (E-29). */
  protected add(): void {
    const to = this.store.steps().find((s) => s.name !== this.step().name)?.name ?? '';
    this.store.updateStep(this.step().name, (step) => ({
      ...step,
      transitions: [
        ...step.transitions,
        {
          to,
          event: step.type === 'interactive' ? 'submit' : null,
          mode: 'assistant',
          condition: { field: '', op: '==', value: '' },
          raw: 'true',
          label: '',
        },
      ],
    }));
  }

  protected remove(index: number): void {
    const name = this.step().name;
    this.opened.update((o) => ({
      ...o,
      [name]: (o[name] ?? []).filter((i) => i !== index).map((i) => (i > index ? i - 1 : i)),
    }));
    this.store.updateStep(this.step().name, (step) => ({
      ...step,
      transitions: step.transitions.filter((_, i) => i !== index),
    }));
  }

  /** «Bedingung…» öffnet den Assistenten (9.14). */
  protected openAssistant(index: number): void {
    const name = this.step().name;
    this.opened.update((o) => ({ ...o, [name]: [...(o[name] ?? []), index] }));
    this.patch(index, (t) => ({ ...t, mode: 'assistant', condition: { ...t.condition } }));
  }

  protected setMode(index: number, mode: 'assistant' | 'raw'): void {
    this.patch(index, (t) => ({
      ...t,
      mode,
      raw: mode === 'raw' && t.mode === 'assistant' ? compileCondition(t.condition) : t.raw,
    }));
  }

  protected setCondition(index: number, change: Partial<EditorTransition['condition']>): void {
    this.patch(index, (t) => ({ ...t, condition: { ...t.condition, ...change } }));
  }

  protected setRaw(index: number, raw: string): void {
    this.patch(index, (t) => ({ ...t, raw }));
  }

  protected setTarget(index: number, to: string): void {
    this.patch(index, (t) => ({ ...t, to }));
  }

  protected setEvent(index: number, event: string): void {
    this.patch(index, (t) => ({ ...t, event: event === '' ? null : event }));
  }

  protected setLabel(index: number, label: string): void {
    this.patch(index, (t) => ({ ...t, label }));
  }
}
