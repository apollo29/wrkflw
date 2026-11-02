import {
  ChangeDetectionStrategy,
  Component,
  computed,
  ElementRef,
  inject,
  output,
} from '@angular/core';
import { WfI18n, WfTranslatePipe } from '../../i18n/i18n';
import { WfButton } from '../../ui/button';
import { WfIcon } from '../../ui/icon';
import { stepKindInfo } from '../../ui/step-kinds';
import { EditorInput } from '../model/definition-mapping';
import { ContextVariable } from '../model/context-vars';
import { stepKind } from '../model/operations';
import { EditorStore } from '../state/editor-store';
import { checkedOf, valueOf } from './dom';

interface StepItem {
  readonly name: string;
  readonly colorClass: string;
  readonly kindLabel: string;
  readonly invalid: boolean;
  readonly reason: string | null;
  readonly unreachable: boolean;
}

/** Seitenspalte (9.6): Schritte, Start-Schritt, Startkontext, Kontext-Variablen. */
@Component({
  selector: 'wf-editor-side',
  imports: [WfButton, WfIcon, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    class: 'wf-zone wf-zone--soft wf-ed-side',
    role: 'complementary',
    '[attr.aria-label]': 'sideLabel',
  },
  template: `
    <section class="wf-ed-section">
      <div class="wf-ed-section__head">
        <h3>{{ 'editor.steps.title' | wfT }}</h3>
        @if (!store.readOnly()) {
          <button type="button" wfButton variant="text" size="sm" (click)="addRequested.emit()">
            {{ 'editor.steps.add' | wfT }}
          </button>
        }
      </div>
      <ul
        class="wf-ed-steps"
        role="listbox"
        [attr.aria-label]="'editor.steps.title' | wfT"
        tabindex="0"
        (keydown)="onKey($event)"
        data-testid="step-list"
      >
        @for (item of items(); track item.name; let i = $index) {
          @if (item.unreachable && (i === 0 || !items()[i - 1]!.unreachable)) {
            <li class="wf-ed-sep wf-hint" role="presentation">
              {{ 'editor.flow.unreachable' | wfT }}
            </li>
          }
          <li
            role="option"
            class="wf-ed-sitem"
            [class]="item.colorClass"
            [class.wf-ed-sitem--on]="store.selected() === item.name"
            [class.wf-ed-sitem--invalid]="item.invalid"
            [attr.aria-selected]="store.selected() === item.name"
            [attr.data-step]="item.name"
            (click)="store.select(item.name)"
          >
            <span class="wf-ed-sitem__sq" aria-hidden="true"></span>
            <span class="wf-ed-sitem__text">
              <span class="wf-ed-sitem__name">{{ item.name }}</span>
              <span class="wf-ed-sitem__kind">{{ item.reason ?? item.kindLabel }}</span>
            </span>
            @if (!store.readOnly()) {
              <button
                type="button"
                wfButton
                variant="icon"
                size="sm"
                tabindex="-1"
                [attr.aria-label]="'editor.steps.delete' | wfT: { name: item.name }"
                (click)="$event.stopPropagation(); store.remove(item.name)"
              >
                <wf-icon name="close" />
              </button>
            }
          </li>
        }
      </ul>
    </section>

    <section class="wf-ed-section">
      <label class="wf-lbl" for="wf-start-step">{{ 'editor.startStep' | wfT }}</label>
      <select
        id="wf-start-step"
        class="wf-inp"
        [disabled]="store.readOnly()"
        (change)="setStart($event)"
      >
        @for (step of store.steps(); track step.name) {
          <option [value]="step.name" [selected]="step.name === store.model().startStep">
            {{ step.name }}
          </option>
        }
      </select>
    </section>

    <section class="wf-ed-section">
      <div class="wf-ed-section__head">
        <h3>{{ 'editor.inputs.title' | wfT }}</h3>
        @if (!store.readOnly()) {
          <button type="button" wfButton variant="text" size="sm" (click)="addInput()">
            {{ 'editor.inputs.add' | wfT }}
          </button>
        }
      </div>
      @for (input of store.model().inputs; track $index; let i = $index) {
        <div class="wf-ed-box">
          <div class="wf-ed-row">
            <input
              class="wf-inp wf-mono"
              style="flex: 1"
              [attr.aria-label]="'editor.inputs.name' | wfT"
              [value]="input.name"
              [readonly]="store.readOnly()"
              (input)="setInput(i, { name: val($event) })"
            />
            <label class="wf-ed-label-inline">
              <input
                type="checkbox"
                class="wf-chk"
                [checked]="input.required"
                [disabled]="store.readOnly()"
                (change)="setInput(i, { required: chk($event) })"
              />
              {{ 'editor.inputs.required' | wfT }}
            </label>
            @if (!store.readOnly()) {
              <button
                type="button"
                wfButton
                variant="icon"
                size="sm"
                [attr.aria-label]="'editor.inputs.remove' | wfT: { name: input.name }"
                (click)="removeInput(i)"
              >
                <wf-icon name="close" />
              </button>
            }
          </div>
          <label class="wf-ed-stack">
            <span class="wf-hint">{{ 'editor.inputs.label' | wfT }}</span>
            <input
              class="wf-inp"
              [value]="input.label"
              [readonly]="store.readOnly()"
              (input)="setInput(i, { label: val($event) })"
            />
          </label>
          <label class="wf-ed-stack">
            <span class="wf-hint">{{ 'editor.inputs.example' | wfT }}</span>
            <input
              class="wf-inp"
              [value]="input.beispiel"
              [readonly]="store.readOnly()"
              (input)="setInput(i, { beispiel: val($event) })"
            />
          </label>
        </div>
      } @empty {
        <p class="wf-hint">{{ 'editor.inputs.empty' | wfT }}</p>
      }
    </section>

    <section class="wf-ed-section">
      <h3>{{ 'editor.vars.title' | wfT }}</h3>
      @if (store.variables().length > 0) {
        <ul class="wf-ed-vars" data-testid="context-vars">
          @for (v of store.variables(); track v.name) {
            <li>
              <span class="wf-mono">{{ token(v.name) }}</span>
              <span class="wf-muted">{{ origin(v) }}</span>
            </li>
          }
        </ul>
      } @else {
        <p class="wf-hint">{{ 'editor.vars.empty' | wfT }}</p>
      }
    </section>
  `,
})
export class WfEditorSide {
  protected readonly store = inject(EditorStore);
  private readonly i18n = inject(WfI18n);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  readonly addRequested = output<void>();
  protected readonly sideLabel = this.i18n.t('editor.side.label');

  protected readonly items = computed<StepItem[]>(() => {
    const byName = new Map(this.store.steps().map((s) => [s.name, s]));
    const errors = this.store.errors();
    const unreachable = this.store.unreachable();
    return this.store.order().map((name) => {
      const step = byName.get(name)!;
      const info = stepKindInfo(stepKind(step));
      const stepErrors = errors.filter((e) => e.step === name);
      const isStart = name === this.store.model().startStep;
      const kindLabel =
        this.i18n.t(info.label) + (isStart ? ' · ' + this.i18n.t('editor.steps.start') : '');
      const reasons = stepErrors.map((e) => e.message);
      if (unreachable.has(name)) {
        reasons.push(this.i18n.t('editor.steps.unreachable'));
      }
      return {
        name,
        colorClass: info.colorClass,
        kindLabel,
        invalid: stepErrors.length > 0,
        reason: reasons.length > 0 ? reasons.join(' · ') : null,
        unreachable: unreachable.has(name),
      };
    });
  });

  protected val = valueOf;
  protected chk = checkedOf;

  protected token(name: string): string {
    return `{{${name}}}`;
  }

  protected origin(v: ContextVariable): string {
    return v.origin.kind === 'input'
      ? this.i18n.t('editor.vars.origin.input')
      : this.i18n.tDynamic(`editor.vars.origin.${v.origin.kind}`, { step: v.origin.step });
  }

  /** Pfeiltasten wählen den nächsten Schritt (9.14: alles per Tastatur). */
  protected onKey(event: KeyboardEvent): void {
    const names = this.items().map((i) => i.name);
    const index = names.indexOf(this.store.selected() ?? '');
    const delta = event.key === 'ArrowDown' ? 1 : event.key === 'ArrowUp' ? -1 : 0;
    if (delta !== 0 && names.length > 0) {
      event.preventDefault();
      const next = names[Math.max(0, Math.min(names.length - 1, index + delta))]!;
      this.store.select(next);
      this.host.nativeElement
        .querySelector(`[data-step="${CSS.escape(next)}"]`)
        ?.scrollIntoView?.({ block: 'nearest' });
    } else if (event.key === 'Delete' && this.store.selected() && !this.store.readOnly()) {
      event.preventDefault();
      this.store.remove(this.store.selected()!);
    }
  }

  protected setStart(event: Event): void {
    const startStep = valueOf(event);
    this.store.update((m) => ({ ...m, startStep }));
  }

  protected addInput(): void {
    // Neue Angaben sind Pflicht (9.6).
    this.store.update((m) => ({
      ...m,
      inputs: [...m.inputs, { name: '', label: '', required: true, beispiel: '' }],
    }));
  }

  protected setInput(index: number, patch: Partial<EditorInput>): void {
    this.store.update((m) => ({
      ...m,
      inputs: m.inputs.map((input, i) => (i === index ? { ...input, ...patch } : input)),
    }));
  }

  protected removeInput(index: number): void {
    this.store.update((m) => ({ ...m, inputs: m.inputs.filter((_, i) => i !== index) }));
  }
}
