import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { WfTranslatePipe } from '../../i18n/i18n';
import { WfField } from '../../ui/field';
import { WfHtmlEditor } from '../html/html-editor';
import { EditorStep } from '../model/definition-mapping';
import { EditorStore } from '../state/editor-store';
import { checkedOf, valueOf } from './dom';

/**
 * Felder einer Aktion, generisch nach ihrem Typ aus `/actions` (9.7). Datencheck und Daten
 * schreiben haben eigene Formulare; hier stehen E-Mail und Aktionen der Host-App.
 */
@Component({
  selector: 'wf-action-fields',
  imports: [WfField, WfHtmlEditor, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-g12' },
  template: `
    @for (field of fields(); track field.name) {
      @switch (field.type) {
        @case ('boolean') {
          <label class="wf-c-12 wf-ed-label-inline">
            <input
              type="checkbox"
              class="wf-chk"
              [checked]="bool(field.name)"
              [disabled]="store.readOnly()"
              (change)="set(field.name, chk($event))"
            />
            {{ field.label }}
          </label>
        }
        @case ('html') {
          @if (!templateChosen()) {
            <div class="wf-c-12 wf-ed-stack">
              <span class="wf-field-label">{{ field.label }}</span>
              <!-- TipTap erst laden, wenn das Feld gebraucht wird (Bundle-Grösse). -->
              @defer (on idle) {
                <wf-html-editor
                  [label]="field.label"
                  [value]="text(field.name)"
                  [placeholders]="placeholders()"
                  [disabled]="store.readOnly()"
                  (valueChange)="set(field.name, $event)"
                />
              } @placeholder {
                <textarea class="wf-inp wf-mono" [value]="text(field.name)" readonly></textarea>
              }
            </div>
          }
        }
        @case ('textarea') {
          <wf-field class="wf-c-12" [label]="field.label">
            <textarea
              class="wf-inp"
              [value]="text(field.name)"
              [readonly]="store.readOnly()"
              (input)="set(field.name, val($event))"
            ></textarea>
          </wf-field>
        }
        @case ('template-ref') {
          <wf-field
            class="wf-c-6 wf-c-sm-12"
            [label]="field.label"
            [hint]="text(field.name) ? ('editor.action.templateOverrides' | wfT) : null"
          >
            <select
              class="wf-inp"
              [disabled]="store.readOnly()"
              (change)="set(field.name, val($event))"
            >
              <option value="">{{ 'editor.action.templateInline' | wfT }}</option>
              @for (t of emailTemplates(); track t.id) {
                <option [value]="t.id" [selected]="t.id === text(field.name)">
                  {{ t.name }} ({{ t.id }})
                </option>
              }
            </select>
          </wf-field>
        }
        @case ('workflow-ref') {
          <wf-field class="wf-c-6 wf-c-sm-12" [label]="field.label">
            <select
              class="wf-inp"
              [disabled]="store.readOnly()"
              (change)="set(field.name, val($event))"
            >
              <option value="">{{ 'editor.workflow.none' | wfT }}</option>
              @for (d of store.catalogs().definitions; track d.id) {
                <option [value]="d.id" [selected]="d.id === text(field.name)">
                  {{ d.name }} ({{ d.id }})
                </option>
              }
            </select>
          </wf-field>
        }
        @default {
          @if (!(templateChosen() && field.name === 'subject')) {
            <wf-field class="wf-c-6 wf-c-sm-12" [label]="field.label">
              <input
                class="wf-inp"
                [value]="text(field.name)"
                [readonly]="store.readOnly()"
                (input)="set(field.name, val($event))"
              />
            </wf-field>
          }
        }
      }
    }
  `,
})
export class WfActionFields {
  readonly step = input.required<EditorStep>();
  protected readonly store = inject(EditorStore);

  protected readonly fields = computed(
    () => this.store.catalogs().actions.find((a) => a.key === this.step().action)?.fields ?? [],
  );
  protected readonly emailTemplates = computed(() =>
    this.store.catalogs().templates.filter((t) => t.type === 'email'),
  );
  protected readonly placeholders = computed(() => this.store.variables().map((v) => v.name));
  /** Mit Vorlage gelten deren Betreff und Inhalt (SendEmailAction). */
  protected readonly templateChosen = computed(() => {
    const value = this.step().config['templateId'];
    return typeof value === 'string' && value !== '';
  });

  protected val = valueOf;
  protected chk = checkedOf;

  protected text(name: string): string {
    const value = this.step().config[name];
    return typeof value === 'string'
      ? value
      : value === undefined || value === null
        ? ''
        : String(value);
  }

  protected bool(name: string): boolean {
    return this.step().config[name] === true;
  }

  protected set(name: string, value: string | boolean): void {
    this.store.updateStep(this.step().name, (step) => {
      const config = { ...step.config };
      if (value === '' || value === false) {
        delete config[name];
      } else {
        config[name] = value;
      }
      return { ...step, config };
    });
  }
}
