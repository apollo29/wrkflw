import { HttpErrorResponse } from '@angular/common/http';
import { Component, ElementRef, inject, OnInit, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  BuilderModel,
  BuilderStep,
  BuilderTransition,
  compileCondition,
  ConditionOp,
  emptyModel,
  emptyStep,
  fromDefinition,
  orderedStepNames,
  toDefinition,
} from './definition-mapping';
import { HtmlEditorComponent } from './html-editor.component';
import {
  ActionCatalogEntry,
  ActionField,
  DefinitionSummary,
  StepType,
  TemplateDetail,
  TemplateSummary,
} from './workflow.models';
import { WorkflowService } from './workflow.service';

/** Action-Key hinter dem Builder-Schritt-Typ „Workflow" (verknüpfte Workflows). */
const START_WORKFLOW_ACTION = 'start_workflow';

/** Builder-Schritt-Art inkl. der Pseudo-Art „workflow" (automatic + start_workflow). */
type StepKind = StepType | 'workflow';

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

const TYPE_LABELS: Record<StepType, string> = {
  automatic: 'Automatisch',
  interactive: 'Interaktiv',
  timer: 'Timer',
};

const TYPE_BADGES: Record<StepType, string> = {
  automatic: 'Automatisch · läuft im Hintergrund',
  interactive: 'Interaktiv · wartet auf Eingabe',
  timer: 'Timer · wartet',
};

/**
 * No-Code-Builder für Workflow-Definitionen: geführte Schrittliste, Konfigurations-
 * formulare, Bedingungs-Assistent und read-only Ablauf-Vorschau. Erzeugt dieselbe
 * Definition-JSON wie der rohe Editor; ein Umschalter zeigt/bearbeitet JSON.
 *
 * Theming über CSS-Variablen (Defaults ergeben ein neutrales, helles Design):
 *   --wfb-primary, --wfb-border, --wfb-bg, --wfb-bg-soft, --wfb-text,
 *   --wfb-text-muted, --wfb-radius
 */
@Component({
  selector: 'wf-builder',
  standalone: true,
  imports: [FormsModule, HtmlEditorComponent],
  templateUrl: './workflow-builder.component.html',
  styleUrl: './workflow-builder.component.css',
})
export class WorkflowBuilderComponent implements OnInit {
  private readonly service = inject(WorkflowService);
  private readonly typePicker = viewChild<ElementRef<HTMLElement>>('typePicker');

  readonly definitions = signal<DefinitionSummary[]>([]);
  readonly actions = signal<ActionCatalogEntry[]>([]);
  readonly templates = signal<TemplateSummary[]>([]);
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

  /**
   * Schritte in Ablauf-Reihenfolge (BFS ab Start-Schritt, wie die Vorschau) —
   * nicht erreichbare Schritte folgen am Ende in Original-Reihenfolge.
   * Liefert Paare mit dem Original-Index, damit Auswahl/Löschen weiter über
   * den model().steps-Index laufen.
   */
  orderedSteps(): { step: BuilderStep; index: number }[] {
    const model = this.model();
    const byName = new Map<string, { step: BuilderStep; index: number }>();
    model.steps.forEach((step, index) => {
      if (!byName.has(step.name)) {
        byName.set(step.name, { step, index });
      }
    });

    const out: { step: BuilderStep; index: number }[] = [];
    const seen = new Set<number>();
    for (const name of orderedStepNames(model)) {
      const entry = byName.get(name);
      if (entry && !seen.has(entry.index)) {
        out.push(entry);
        seen.add(entry.index);
      }
    }
    model.steps.forEach((step, index) => {
      if (!seen.has(index)) {
        out.push({ step, index });
      }
    });
    return out;
  }

  selectedStep(): BuilderStep | null {
    const steps = this.model().steps;
    const i = this.selected();
    return i >= 0 && i < steps.length ? steps[i] : null;
  }

  stepByName(name: string): BuilderStep | null {
    return this.model().steps.find((s) => s.name === name) ?? null;
  }

  typeLabel(type: StepType): string {
    return TYPE_LABELS[type];
  }

  typeBadge(type: StepType): string {
    return TYPE_BADGES[type];
  }

  /** Kompilierter when-Ausdruck für die Vorschau-Zeile unter dem Assistenten. */
  compiledCondition(t: BuilderTransition): string {
    return t.mode === 'assistant' ? compileCondition(t.condition) : t.raw;
  }

  /** "Sonst"-Übergang: Assistent-Modus ohne Feld (== immer wahr). */
  isElse(t: BuilderTransition): boolean {
    return t.mode === 'assistant' && t.condition.field.trim() === '';
  }

  scrollToTypePicker(): void {
    this.typePicker()?.nativeElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  ngOnInit(): void {
    this.reloadDefinitions();
    this.service.listActions().subscribe({
      next: (res) => this.actions.set(res.actions),
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
    this.service.listTemplates().subscribe({
      next: (res) => this.templates.set(res.templates),
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

  configBool(step: BuilderStep, name: string): boolean {
    return step.config[name] === true;
  }

  setConfigBool(step: BuilderStep, name: string, value: boolean): void {
    step.config[name] = value;
  }

  // -- Template-Filter nach Typ ------------------------------------------

  /** E-Mail-Vorlagen (für den 'template-ref'-Feldtyp der send_email-Action). */
  emailTemplates(): TemplateSummary[] {
    return this.templates().filter((t) => t.type === 'email');
  }

  /** Seiten-Vorlagen (für die 'Seitenvorlage' interaktiver Schritte). */
  pageTemplates(): TemplateSummary[] {
    return this.templates().filter((t) => t.type === 'page');
  }

  // -- Workflow-Schritt (Builder-Zucker über automatic + start_workflow) --

  /** Ist der Schritt ein „Workflow"-Schritt (verknüpft einen anderen Workflow)? */
  isWorkflowStep(step: BuilderStep): boolean {
    return step.type === 'automatic' && step.action === START_WORKFLOW_ACTION;
  }

  /** Anzeige-Art des Schritts inkl. der Pseudo-Art „workflow". */
  stepKind(step: BuilderStep): StepKind {
    return this.isWorkflowStep(step) ? 'workflow' : step.type;
  }

  /** Icon-Symbol-Referenz für eine Schritt-Art. */
  kindIcon(kind: StepKind): string {
    switch (kind) {
      case 'interactive':
        return '#wfb-i-inter';
      case 'timer':
        return '#wfb-i-timer';
      case 'workflow':
        return '#wfb-i-flow';
      default:
        return '#wfb-i-auto';
    }
  }

  stepIcon(step: BuilderStep): string {
    return this.kindIcon(this.stepKind(step));
  }

  /** Setzt die Schritt-Art aus dem Typ-Dropdown (inkl. „workflow"). */
  setKind(step: BuilderStep, kind: string): void {
    if (kind === 'workflow') {
      step.type = 'automatic';
      step.action = START_WORKFLOW_ACTION;
    } else {
      if (step.action === START_WORKFLOW_ACTION) {
        step.action = null;
      }
      step.type = kind as StepType;
    }
    this.bump();
  }

  /** Fügt einen „Workflow"-Schritt hinzu (automatic + start_workflow). */
  addWorkflowStep(): void {
    this.addStep('automatic');
    const step = this.selectedStep();
    if (step) {
      step.action = START_WORKFLOW_ACTION;
      this.bump();
    }
  }

  /**
   * Auswahlbare Ziel-Workflows (fuer den 'workflow-ref'-Feldtyp): je Definition-ID
   * ein Eintrag (aktiver/neuester Name), nach Name sortiert.
   */
  workflowOptions(): { id: string; name: string }[] {
    const byId = new Map<string, string>();
    for (const d of this.definitions()) {
      if (!byId.has(d.id) || d.active) {
        byId.set(d.id, d.name || d.id);
      }
    }
    return [...byId.entries()]
      .map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }

  // -- Template-Vorschau (template-ref) -----------------------------------

  private readonly templateDetails = signal<Record<string, TemplateDetail>>({});
  private readonly loadingTemplates = new Set<string>();

  /**
   * Liefert das vollständige Template zur ID (Betreff + Body) für die Inline-Vorschau.
   * Lädt es einmalig nach und cached es; bis dahin `null`.
   */
  templatePreview(id: string): TemplateDetail | null {
    if (!id) {
      return null;
    }
    const cached = this.templateDetails()[id];
    if (cached) {
      return cached;
    }
    if (!this.loadingTemplates.has(id)) {
      this.loadingTemplates.add(id);
      this.service.getTemplate(id).subscribe({
        next: (t) => this.templateDetails.update((m) => ({ ...m, [id]: t })),
        error: () => this.loadingTemplates.delete(id),
      });
    }
    return null;
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
