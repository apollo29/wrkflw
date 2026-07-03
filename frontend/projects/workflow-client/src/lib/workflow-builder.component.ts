import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  BuilderModel,
  BuilderStep,
  BuilderTransition,
  ConditionOp,
  emptyModel,
  emptyStep,
  fromDefinition,
  orderedStepNames,
  toDefinition,
} from './definition-mapping';
import { ActionCatalogEntry, ActionField, DefinitionSummary, StepType } from './workflow.models';
import { WorkflowService } from './workflow.service';

const OPERATORS: { op: ConditionOp; label: string }[] = [
  { op: '==', label: 'ist' },
  { op: '!=', label: 'ist nicht' },
  { op: '>', label: 'größer als' },
  { op: '<', label: 'kleiner als' },
  { op: '>=', label: 'größer/gleich' },
  { op: '<=', label: 'kleiner/gleich' },
];

const UNITS: { unit: string; label: string; factor: number }[] = [
  { unit: 'minutes', label: 'Minuten', factor: 60 },
  { unit: 'hours', label: 'Stunden', factor: 3600 },
  { unit: 'days', label: 'Tage', factor: 86400 },
];

/**
 * No-Code-Builder für Workflow-Definitionen: geführte Schrittliste, Konfigurations-
 * formulare, Bedingungs-Assistent und read-only Ablauf-Vorschau. Erzeugt dieselbe
 * Definition-JSON wie der rohe Editor; ein „Erweitert"-Umschalter zeigt/bearbeitet JSON.
 */
@Component({
  selector: 'wf-builder',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './workflow-builder.component.html',
  styles: [
    `
      .wf-b { display: flex; flex-direction: column; gap: 12px; }
      .wf-b__bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
      .wf-b__bar input { flex: 1; min-width: 140px; }
      .wf-b__cols { display: grid; grid-template-columns: 220px 1fr; gap: 12px; align-items: start; }
      .wf-b__panel { border: 0.5px solid var(--border, #ccc); border-radius: 12px; padding: 12px; }
      .wf-b__steplist { display: flex; flex-direction: column; gap: 4px; }
      .wf-b__step { text-align: left; }
      .wf-b__step--active { font-weight: 500; }
      .wf-b__row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 6px 0; }
      .wf-b__label { font-size: 12px; color: var(--text-secondary, #666); display: block; margin: 8px 0 4px; }
      .wf-b__preview { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
      .wf-b__chip { font-size: 12px; padding: 4px 8px; border: 0.5px solid var(--border, #ccc); border-radius: 6px; }
      .wf-b__error { color: #b00020; }
      .wf-b__ok { color: #0a7d28; }
      textarea.wf-b__json { width: 100%; font-family: monospace; }
      table.wf-b__fields { width: 100%; border-collapse: collapse; }
      table.wf-b__fields td { padding: 2px; }
    `,
  ],
})
export class WorkflowBuilderComponent implements OnInit {
  private readonly service = inject(WorkflowService);

  readonly definitions = signal<DefinitionSummary[]>([]);
  readonly actions = signal<ActionCatalogEntry[]>([]);
  readonly model = signal<BuilderModel>(emptyModel());
  readonly selected = signal<number>(-1);
  readonly viewMode = signal<'visual' | 'json'>('visual');
  readonly jsonText = signal<string>('');
  readonly busy = signal<boolean>(false);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  readonly operators = OPERATORS;
  readonly units = UNITS;

  preview(): string[] {
    return orderedStepNames(this.model());
  }

  selectedStep(): BuilderStep | null {
    const steps = this.model().steps;
    const i = this.selected();
    return i >= 0 && i < steps.length ? steps[i] : null;
  }

  ngOnInit(): void {
    this.reloadDefinitions();
    this.service.listActions().subscribe({
      next: (res) => this.actions.set(res.actions),
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  reloadDefinitions(): void {
    this.service.listDefinitions().subscribe({
      next: (res) => this.definitions.set(res.definitions),
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  newDefinition(): void {
    this.model.set(emptyModel());
    this.selected.set(-1);
    this.viewMode.set('visual');
    this.resetMessages();
  }

  loadDefinition(id: string): void {
    this.resetMessages();
    this.service.getDefinition(id).subscribe({
      next: (res) => {
        this.model.set(fromDefinition(res.definition));
        this.selected.set(this.model().steps.length > 0 ? 0 : -1);
        this.viewMode.set('visual');
      },
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  // -- Schritte -----------------------------------------------------------

  addStep(type: StepType): void {
    const model = this.model();
    const name = this.uniqueStepName();
    const steps = [...model.steps, emptyStep(name, type)];
    const startStep = model.startStep === '' ? name : model.startStep;
    this.model.set({ ...model, steps, startStep });
    this.selected.set(steps.length - 1);
  }

  removeStep(index: number): void {
    const model = this.model();
    const steps = model.steps.filter((_, i) => i !== index);
    this.model.set({ ...model, steps });
    this.selected.set(Math.min(index, steps.length - 1));
  }

  private uniqueStepName(): string {
    const existing = new Set(this.model().steps.map((s) => s.name));
    let n = existing.size + 1;
    let name = `schritt_${n}`;
    while (existing.has(name)) {
      n += 1;
      name = `schritt_${n}`;
    }
    return name;
  }

  stepNames(): string[] {
    return this.model().steps.map((s) => s.name);
  }

  fieldSuggestions(): string[] {
    const names = new Set<string>();
    for (const step of this.model().steps) {
      for (const field of step.fields) {
        if (field.name) {
          names.add(field.name);
        }
      }
    }
    return [...names];
  }

  bump(): void {
    this.model.set({ ...this.model() });
  }

  // -- Felder (interaktiv) ------------------------------------------------

  addField(step: BuilderStep): void {
    step.fields.push({ name: 'feld', label: 'Feld', type: 'text' });
    this.bump();
  }

  removeField(step: BuilderStep, index: number): void {
    step.fields.splice(index, 1);
    this.bump();
  }

  // -- Action-Config (automatisch) ---------------------------------------

  setType(step: BuilderStep, value: string): void {
    step.type = value as StepType;
    this.bump();
  }

  setAction(step: BuilderStep, value: string): void {
    step.action = value === '' ? null : value;
    this.bump();
  }

  setOp(t: BuilderTransition, value: string): void {
    t.condition.op = value as ConditionOp;
  }

  setFieldType(field: { type: string }, value: string): void {
    field.type = value;
  }

  actionSchema(step: BuilderStep): ActionField[] {
    return this.actions().find((a) => a.key === step.action)?.config ?? [];
  }

  configValue(step: BuilderStep, name: string): string {
    const value = step.config[name];
    return value === undefined || value === null ? '' : String(value);
  }

  setConfig(step: BuilderStep, name: string, value: string): void {
    step.config[name] = value;
  }

  // -- Timer --------------------------------------------------------------

  timerValue(step: BuilderStep): number {
    const seconds = step.delaySeconds ?? 0;
    for (const u of [...UNITS].reverse()) {
      if (seconds % u.factor === 0 && seconds >= u.factor) {
        return seconds / u.factor;
      }
    }
    return seconds;
  }

  timerUnit(step: BuilderStep): string {
    const seconds = step.delaySeconds ?? 0;
    for (const u of [...UNITS].reverse()) {
      if (seconds % u.factor === 0 && seconds >= u.factor) {
        return u.unit;
      }
    }
    return 'minutes';
  }

  setTimer(step: BuilderStep, value: number, unit: string): void {
    const factor = UNITS.find((u) => u.unit === unit)?.factor ?? 60;
    step.delaySeconds = Math.max(0, Math.round(value)) * factor;
    this.bump();
  }

  // -- Übergänge ----------------------------------------------------------

  addTransition(step: BuilderStep): void {
    const t: BuilderTransition = {
      to: '',
      event: step.type === 'interactive' ? 'submit' : null,
      mode: 'assistant',
      condition: { field: '', op: '==', value: '' },
      raw: 'true',
    };
    step.transitions.push(t);
    this.bump();
  }

  removeTransition(step: BuilderStep, index: number): void {
    step.transitions.splice(index, 1);
    this.bump();
  }

  toggleConditionMode(t: BuilderTransition): void {
    t.mode = t.mode === 'assistant' ? 'raw' : 'assistant';
    this.bump();
  }

  // -- JSON-Umschalter ----------------------------------------------------

  showJson(): void {
    this.jsonText.set(JSON.stringify(toDefinition(this.model()), null, 2));
    this.viewMode.set('json');
  }

  showVisual(): void {
    try {
      const parsed = JSON.parse(this.jsonText()) as Record<string, unknown>;
      this.model.set(fromDefinition(parsed));
      this.selected.set(this.model().steps.length > 0 ? 0 : -1);
      this.viewMode.set('visual');
      this.error.set(null);
    } catch {
      this.error.set('Ungültiges JSON – bitte korrigieren.');
    }
  }

  // -- Speichern ----------------------------------------------------------

  save(): void {
    this.resetMessages();

    let definition: Record<string, unknown>;
    let id: string;
    let name: string;

    if (this.viewMode() === 'json') {
      try {
        definition = JSON.parse(this.jsonText()) as Record<string, unknown>;
      } catch {
        this.error.set('Ungültiges JSON.');
        return;
      }
      id = String(definition['id'] ?? '');
      name = String(definition['name'] ?? id);
    } else {
      const model = this.model();
      definition = toDefinition(model);
      id = model.id.trim();
      name = model.name.trim() || id;
    }

    if (id === '') {
      this.error.set('Bitte eine ID für den Workflow angeben.');
      return;
    }

    this.busy.set(true);
    this.service.saveDefinition(id, name, definition).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.message.set(`Gespeichert: ${res.id} v${res.version}.`);
        this.reloadDefinitions();
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.error.set(this.apiError(err));
      },
    });
  }

  private resetMessages(): void {
    this.message.set(null);
    this.error.set(null);
  }

  private apiError(err: unknown): string {
    if (err instanceof HttpErrorResponse) {
      const body = err.error as { error?: { message?: string } } | null;
      if (body?.error?.message) {
        return body.error.message;
      }
      return `HTTP ${err.status}`;
    }
    return 'Unbekannter Fehler';
  }
}
