import {
  ChangeDetectionStrategy,
  Component,
  computed,
  Directive,
  inject,
  input,
} from '@angular/core';
import type { ModelInfo } from '../../api/models';
import { WfTranslatePipe } from '../../i18n/i18n';
import { WfField } from '../../ui/field';
import { EditorStep } from '../model/definition-mapping';
import { EditorStore } from '../state/editor-store';
import { checkedOf, valueOf } from './dom';

@Directive()
abstract class DataForm {
  readonly step = input.required<EditorStep>();
  protected readonly store = inject(EditorStore);
  protected val = valueOf;
  protected chk = checkedOf;

  protected text(name: string, fallback = ''): string {
    const value = this.step().config[name];
    return typeof value === 'string' ? value : fallback;
  }

  protected setConfig(name: string, value: unknown): void {
    this.store.updateStep(this.step().name, (step) => {
      const config = { ...step.config };
      if (
        value === '' ||
        value === false ||
        value === undefined ||
        (Array.isArray(value) && value.length === 0)
      ) {
        delete config[name];
      } else {
        config[name] = value;
      }
      return { ...step, config };
    });
  }

  protected token(name: string): string {
    return `{{${name}}}`;
  }
}

/** Datencheck (9.7): Tabelle, Datensatz, Feld, Ergebnis, weitere Felder. */
@Component({
  selector: 'wf-data-check-form',
  imports: [WfField, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-g12' },
  template: `
    <wf-field
      class="wf-c-6 wf-c-sm-12"
      [label]="'editor.data.table' | wfT"
      [hint]="store.catalogs().models.length === 0 ? ('editor.data.table.empty' | wfT) : null"
    >
      <select
        class="wf-inp"
        [disabled]="store.readOnly()"
        (change)="setEntity($event)"
        data-testid="data-table"
      >
        <option value="">{{ 'editor.data.table.none' | wfT }}</option>
        @for (m of store.catalogs().models; track m.name) {
          <option [value]="m.name" [selected]="m.name === text('entity')">
            {{ m.label }} ({{ m.name }})
          </option>
        }
      </select>
    </wf-field>
    <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.data.id' | wfT">
      <input
        class="wf-inp wf-mono"
        [value]="text('id')"
        [readonly]="store.readOnly()"
        (input)="setConfig('id', val($event))"
      />
    </wf-field>
    <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.data.field' | wfT">
      <select
        class="wf-inp"
        [disabled]="store.readOnly() || !model()"
        (change)="setConfig('field', val($event))"
      >
        <option value="">{{ 'editor.data.field.none' | wfT }}</option>
        @for (f of model()?.fields ?? []; track f.name) {
          <option [value]="f.name" [selected]="f.name === text('field')">
            {{ f.label }} ({{ f.name }})
          </option>
        }
      </select>
    </wf-field>
    <wf-field
      class="wf-c-6 wf-c-sm-12"
      [label]="'editor.data.as' | wfT"
      [hint]="'editor.data.check.asHint' | wfT: { as: asName() }"
    >
      <input
        class="wf-inp wf-mono"
        [value]="text('as')"
        placeholder="checkedValue"
        [readonly]="store.readOnly()"
        (input)="setConfig('as', val($event))"
      />
    </wf-field>
    <fieldset class="wf-c-12 wf-ed-stack" style="border: 0; padding: 0; margin: 0">
      <legend class="wf-field-label">{{ 'editor.data.fields' | wfT }}</legend>
      @if (model(); as m) {
        <div class="wf-ed-checks">
          @for (f of m.fields; track f.name) {
            <label class="wf-ed-label-inline">
              <input
                type="checkbox"
                class="wf-chk"
                [checked]="extra().includes(f.name)"
                [disabled]="store.readOnly()"
                (change)="toggleExtra(m, f.name, chk($event))"
              />
              {{ f.label }}
            </label>
          }
        </div>
        @if (extra().length > 0) {
          <span class="wf-hint">{{ 'editor.data.fields.hint' | wfT: { vars: extraVars() } }}</span>
        }
      } @else {
        <span class="wf-hint">{{ 'editor.data.fields.first' | wfT }}</span>
      }
    </fieldset>
  `,
})
export class WfDataCheckForm extends DataForm {
  protected readonly model = computed<ModelInfo | null>(
    () => this.store.catalogs().models.find((m) => m.name === this.text('entity')) ?? null,
  );
  protected readonly asName = computed(() => this.text('as') || 'checkedValue');
  protected readonly extra = computed(() => {
    const fields = this.step().config['fields'];
    return Array.isArray(fields) ? fields.filter((f): f is string => typeof f === 'string') : [];
  });
  protected readonly extraVars = computed(() =>
    this.extra()
      .map((f) => this.token(`${this.asName()}_${f}`))
      .join(', '),
  );

  protected setEntity(event: Event): void {
    const entity = valueOf(event);
    this.store.updateStep(this.step().name, (step) => {
      const { field: _field, fields: _fields, ...rest } = step.config;
      return { ...step, config: entity === '' ? rest : { ...rest, entity } };
    });
  }

  /** Reihenfolge wie im Katalog. */
  protected toggleExtra(model: ModelInfo, name: string, on: boolean): void {
    const chosen = new Set(this.extra());
    if (on) {
      chosen.add(name);
    } else {
      chosen.delete(name);
    }
    this.setConfig(
      'fields',
      model.fields.map((f) => f.name).filter((f) => chosen.has(f)),
    );
  }
}

interface WriteValue {
  readonly wert: string;
  readonly wenn: string;
  readonly sonst: string;
  readonly conditional: boolean;
}

/** Daten schreiben (9.7, E-20 bis E-22). */
@Component({
  selector: 'wf-data-write-form',
  imports: [WfField, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-g12' },
  template: `
    <wf-field
      class="wf-c-6 wf-c-sm-12"
      [label]="'editor.data.table' | wfT"
      [hint]="
        (store.catalogs().writableModels.length === 0
          ? 'editor.data.table.empty'
          : 'editor.data.table.writeHint'
        ) | wfT
      "
    >
      <select
        class="wf-inp"
        [disabled]="store.readOnly()"
        (change)="setEntity($event)"
        data-testid="write-table"
      >
        <option value="">{{ 'editor.data.table.none' | wfT }}</option>
        @for (m of store.catalogs().writableModels; track m.name) {
          <option [value]="m.name" [selected]="m.name === text('entity')">
            {{ m.label }} ({{ m.name }})
          </option>
        }
      </select>
    </wf-field>
    <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.data.id' | wfT">
      <input
        class="wf-inp wf-mono"
        [value]="text('id')"
        [readonly]="store.readOnly()"
        (input)="setConfig('id', val($event))"
      />
    </wf-field>

    <div class="wf-c-12 wf-ed-stack">
      <span class="wf-field-label">{{ 'editor.data.write.values' | wfT }}</span>
      @if (model(); as m) {
        <div class="wf-ed-list">
          @for (f of m.fields; track f.name) {
            @let v = valueFor(f.name);
            <div class="wf-ed-list__row wf-g12">
              <label class="wf-c-3 wf-c-md-12 wf-ed-label-inline">
                <input
                  type="checkbox"
                  class="wf-chk"
                  [checked]="v !== null"
                  [disabled]="store.readOnly()"
                  (change)="toggle(f.name, chk($event))"
                />
                {{ f.label }} <span class="wf-mono wf-muted">{{ f.name }}</span>
              </label>
              @if (v !== null) {
                <input
                  class="wf-inp wf-c-6 wf-c-md-8 wf-c-sm-12"
                  [attr.aria-label]="('editor.data.write.value' | wfT) + ' ' + f.label"
                  [placeholder]="'editor.data.write.valuePlaceholder' | wfT"
                  [value]="v.wert"
                  [readonly]="store.readOnly()"
                  (input)="patch(f.name, { wert: val($event) })"
                />
                <label class="wf-c-3 wf-c-md-4 wf-c-sm-12 wf-ed-label-inline">
                  <input
                    type="checkbox"
                    class="wf-chk"
                    [checked]="v.conditional"
                    [disabled]="store.readOnly()"
                    (change)="patch(f.name, { conditional: chk($event) })"
                  />
                  {{ 'editor.data.write.conditional' | wfT }}
                </label>
                @if (v.conditional) {
                  <div class="wf-c-12 wf-ed-cond wf-g12">
                    <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.data.write.when' | wfT">
                      <input
                        class="wf-inp wf-mono"
                        [value]="v.wenn"
                        placeholder="context['x'] == 'y'"
                        [readonly]="store.readOnly()"
                        (input)="patch(f.name, { wenn: val($event) })"
                      />
                    </wf-field>
                    <wf-field class="wf-c-6 wf-c-sm-12" [label]="'editor.data.write.else' | wfT">
                      <input
                        class="wf-inp"
                        [value]="v.sonst"
                        [placeholder]="'editor.data.write.elsePlaceholder' | wfT"
                        [readonly]="store.readOnly()"
                        (input)="patch(f.name, { sonst: val($event) })"
                      />
                    </wf-field>
                    <span class="wf-c-12 wf-hint">{{ 'editor.data.write.elseHint' | wfT }}</span>
                  </div>
                }
              }
            </div>
          } @empty {
            <div class="wf-ed-list__row wf-hint">{{ 'editor.data.table.empty' | wfT }}</div>
          }
        </div>
        <span class="wf-hint">{{ 'editor.data.write.timeHint' | wfT }}</span>
      } @else {
        <span class="wf-hint">{{ 'editor.data.fields.first' | wfT }}</span>
      }
    </div>

    <div class="wf-c-12 wf-ed-stack">
      <label class="wf-ed-label-inline">
        <input
          type="checkbox"
          class="wf-chk"
          [checked]="step().config['anlegen'] === true"
          [disabled]="store.readOnly()"
          (change)="setConfig('anlegen', chk($event))"
        />
        {{ 'editor.data.write.create' | wfT }}
      </label>
      <span class="wf-hint">{{ 'editor.data.write.createHint' | wfT }}</span>
    </div>
    <wf-field
      class="wf-c-6 wf-c-sm-12"
      [label]="'editor.data.as' | wfT"
      [hint]="'editor.data.write.asHint' | wfT: { as: text('as') || 'written' }"
    >
      <input
        class="wf-inp wf-mono"
        [value]="text('as')"
        placeholder="written"
        [readonly]="store.readOnly()"
        (input)="setConfig('as', val($event))"
      />
    </wf-field>
  `,
})
export class WfDataWriteForm extends DataForm {
  protected readonly model = computed<ModelInfo | null>(() => {
    const model = this.store.catalogs().writableModels.find((m) => m.name === this.text('entity'));
    return model ? { ...model, fields: model.fields.filter((f) => f.writable) } : null;
  });

  private readonly values = computed<Record<string, WriteValue>>(() => {
    const raw = this.step().config['values'];
    const out: Record<string, WriteValue> = {};
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
      for (const [name, value] of Object.entries(raw as Record<string, unknown>)) {
        if (value !== null && typeof value === 'object') {
          const v = value as Record<string, unknown>;
          out[name] = {
            wert: typeof v['wert'] === 'string' ? v['wert'] : '',
            wenn: typeof v['wenn'] === 'string' ? v['wenn'] : '',
            sonst: typeof v['sonst'] === 'string' ? v['sonst'] : '',
            conditional: typeof v['wenn'] === 'string',
          };
        } else {
          out[name] = {
            wert: value === null || value === undefined ? '' : String(value),
            wenn: '',
            sonst: '',
            conditional: false,
          };
        }
      }
    }
    return out;
  });

  protected valueFor(name: string): WriteValue | null {
    return this.values()[name] ?? null;
  }

  protected setEntity(event: Event): void {
    const entity = valueOf(event);
    this.store.updateStep(this.step().name, (step) => {
      const { values: _values, ...rest } = step.config;
      return { ...step, config: entity === '' ? rest : { ...rest, entity } };
    });
  }

  protected toggle(name: string, on: boolean): void {
    const next = { ...this.values() };
    if (on) {
      next[name] = { wert: '', wenn: '', sonst: '', conditional: false };
    } else {
      delete next[name];
    }
    this.write(next);
  }

  protected patch(name: string, change: Partial<WriteValue>): void {
    const current = this.values()[name] ?? { wert: '', wenn: '', sonst: '', conditional: false };
    this.write({ ...this.values(), [name]: { ...current, ...change } });
  }

  /** `{spalte: "wert"}` oder `{spalte: {wert, wenn, sonst?}}`; leeres «Sonst» = unverändert (E-21). */
  private write(values: Record<string, WriteValue>): void {
    const order = this.model()?.fields.map((f) => f.name) ?? Object.keys(values);
    const out: Record<string, unknown> = {};
    for (const name of order) {
      const v = values[name];
      if (!v) {
        continue;
      }
      if (v.conditional) {
        out[name] = { wert: v.wert, wenn: v.wenn, ...(v.sonst !== '' ? { sonst: v.sonst } : {}) };
      } else {
        out[name] = v.wert;
      }
    }
    this.setConfig('values', Object.keys(out).length > 0 ? out : undefined);
  }
}
