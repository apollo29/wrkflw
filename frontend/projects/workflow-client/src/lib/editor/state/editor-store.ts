import { computed, DestroyRef, effect, inject, Injectable, signal, untracked } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { WfApiError } from '../../api/api-error';
import type {
  ActionInfo,
  DefinitionStatus,
  DefinitionSummary,
  JsonObject,
  ModelInfo,
  TemplateSummary,
  UploadHandler,
  ValidationError,
} from '../../api/models';
import { WorkflowApi } from '../../api/workflow-api';
import { WfI18n } from '../../i18n/i18n';
import type { WfMessageKey } from '../../i18n/messages.de';
import type { StepKind } from '../../ui/step-kinds';
import { WfToastService } from '../../ui/toast';
import {
  emptyModel,
  EditorModel,
  EditorStep,
  fromDefinition,
  orderedStepNames,
  removeStep,
  toDefinition,
} from '../model/definition-mapping';
import { contextVariables } from '../model/context-vars';
import { flowSections } from '../model/flow';
import { localChecks } from '../model/local-checks';
import { addStep, nameError, renameStep, setKind, updateStep } from '../model/operations';

export type EditorView = 'visual' | 'json';

export interface EditorIssue {
  readonly step: string | null;
  readonly message: string;
  readonly severity: 'error' | 'hint';
  readonly code: string;
  readonly transition?: number;
  readonly field?: number;
}

export interface EditorMessage {
  readonly tone: 'ok' | 'error';
  readonly text: string;
}

export interface EditorCatalogs {
  readonly actions: readonly ActionInfo[];
  readonly models: readonly ModelInfo[];
  readonly writableModels: readonly ModelInfo[];
  readonly uploadHandlers: readonly UploadHandler[];
  readonly templates: readonly TemplateSummary[];
  readonly definitions: readonly DefinitionSummary[];
}

const EMPTY_CATALOGS: EditorCatalogs = {
  actions: [],
  models: [],
  writableModels: [],
  uploadHandlers: [],
  templates: [],
  definitions: [],
};

/** Prüfung beim Server, nach der letzten Änderung (ms). */
export const VALIDATE_DEBOUNCE_MS = 400;

function canonical(definition: JsonObject, status: DefinitionStatus): string {
  return JSON.stringify({ definition, status });
}

/**
 * Zustand eines geöffneten Editors (pro Editor-Komponente bereitgestellt). Alles, was die
 * Oberfläche zeigt, ist ein Signal; Änderungen gehen nur über die Methoden.
 */
@Injectable()
export class EditorStore {
  private readonly api = inject(WorkflowApi);
  private readonly i18n = inject(WfI18n);
  private readonly toasts = inject(WfToastService);

  readonly model = signal<EditorModel>(emptyModel());
  readonly selected = signal<string | null>(null);
  readonly status = signal<DefinitionStatus>('active');
  readonly view = signal<EditorView>('visual');
  readonly jsonText = signal('');
  readonly message = signal<EditorMessage | null>(null);
  readonly busy = signal(false);
  readonly loading = signal(false);
  readonly loadError = signal<string | null>(null);
  /** Gespeicherte Version; null bei einem neuen Workflow. */
  readonly version = signal<number | null>(null);
  readonly readOnly = signal(false);
  readonly catalogs = signal<EditorCatalogs>(EMPTY_CATALOGS);
  readonly serverErrors = signal<readonly ValidationError[]>([]);

  private readonly snapshot = signal(canonical(toDefinition(emptyModel()), 'active'));
  private validateTimer: ReturnType<typeof setTimeout> | null = null;

  readonly isNew = computed(() => this.version() === null);
  readonly steps = computed(() => this.model().steps);
  readonly order = computed(() => orderedStepNames(this.model()));
  readonly sections = computed(() => flowSections(this.model()));
  readonly variables = computed(() => contextVariables(this.model()));
  readonly selectedStep = computed<EditorStep | null>(
    () => this.model().steps.find((s) => s.name === this.selected()) ?? null,
  );

  /** Aktuelle Definition: in der JSON-Ansicht der Text, sonst das Formular. */
  private readonly currentText = computed(() => {
    if (this.view() === 'json') {
      try {
        return canonical(JSON.parse(this.jsonText()) as JsonObject, this.status());
      } catch {
        return null;
      }
    }
    return canonical(toDefinition(this.model()), this.status());
  });

  readonly dirty = computed(() => this.currentText() !== this.snapshot());

  readonly issues = computed<EditorIssue[]>(() => {
    const catalog = this.catalogs().actions;
    const local = localChecks(this.model(), {
      hasAction: (key) => catalog.length === 0 || catalog.some((a) => a.key === key),
    });
    const issues: EditorIssue[] = local.map((c) => ({
      ...c,
      message: this.i18n.tDynamic(`validation.${c.code}`),
    }));
    const seen = new Set(local.map((c) => `${c.code}|${c.step}|${c.transition ?? ''}`));
    for (const e of this.serverErrors()) {
      if (!seen.has(`${e.code}|${e.step}|${e.transition ?? ''}`)) {
        issues.push({
          code: e.code,
          step: e.step,
          message: e.message,
          severity: 'error',
          ...(e.transition !== null ? { transition: e.transition } : {}),
        });
      }
    }
    return issues;
  });

  readonly errors = computed(() => this.issues().filter((i) => i.severity === 'error'));
  readonly stepsWithErrors = computed(
    () =>
      new Set(
        this.errors()
          .map((e) => e.step)
          .filter((s): s is string => s !== null),
      ),
  );
  readonly unreachable = computed(() => {
    const last = this.sections().at(-1);
    return new Set(last?.kind === 'unreachable' ? last.steps : []);
  });

  /** Aktiv nur ohne Fehler; Entwurf und Inaktiv immer (Rückfrage Phase 7). */
  readonly canSave = computed(
    () =>
      !this.busy() &&
      !this.readOnly() &&
      (this.status() !== 'active' || this.view() === 'json' || this.errors().length === 0),
  );

  constructor() {
    // Laufende Prüfung beim Server, entprellt.
    effect(() => {
      const model = this.model();
      if (this.readOnly() || this.view() === 'json' || model.steps.length === 0) {
        return;
      }
      untracked(() => this.scheduleValidation(model));
    });
    inject(DestroyRef).onDestroy(() => {
      if (this.validateTimer) {
        clearTimeout(this.validateTimer);
      }
    });
  }

  // ------------------------------------------------------------ Laden

  async load(id: string, version?: number): Promise<void> {
    this.loading.set(true);
    this.loadError.set(null);
    try {
      const [loaded] = await Promise.all([
        firstValueFrom(
          version === undefined
            ? this.api.definitions.get(id)
            : this.api.definitions.version(id, version),
        ),
        this.loadCatalogs(),
      ]);
      const model = fromDefinition({ ...loaded.definition, id: loaded.id });
      this.model.set(model);
      this.status.set(loaded.status);
      this.version.set(loaded.version);
      this.readOnly.set(loaded.readOnly);
      this.selected.set(orderedStepNames(model)[0] ?? null);
      this.view.set('visual');
      this.markSaved();
    } catch (error) {
      this.loadError.set(this.errorText(WfApiError.from(error)));
    } finally {
      this.loading.set(false);
    }
  }

  async newWorkflow(): Promise<void> {
    this.model.set(emptyModel());
    this.status.set('active');
    this.version.set(null);
    this.readOnly.set(false);
    this.selected.set(null);
    this.markSaved();
    await this.loadCatalogs();
  }

  private async loadCatalogs(): Promise<void> {
    // Fehlende Kataloge sind kein Fehler: die Listen bleiben leer, die Felder erklären es (E-39).
    const safe = <T>(p: Promise<T>, fallback: T): Promise<T> => p.catch(() => fallback);
    const [actions, models, writable, handlers, templates, definitions] = await Promise.all([
      safe(
        firstValueFrom(this.api.catalogs.actions()).then((r) => r.actions),
        [],
      ),
      safe(
        firstValueFrom(this.api.catalogs.dataModels()).then((r) => r.models),
        [],
      ),
      safe(
        firstValueFrom(this.api.catalogs.dataModels(true)).then((r) => r.models),
        [],
      ),
      safe(
        firstValueFrom(this.api.catalogs.uploadHandlers()).then((r) => r.handlers),
        [],
      ),
      safe(
        firstValueFrom(this.api.templates.list()).then((r) => r.templates),
        [],
      ),
      safe(
        firstValueFrom(this.api.definitions.list()).then((r) => r.definitions),
        [],
      ),
    ]);
    this.catalogs.set({
      actions,
      models,
      writableModels: writable,
      uploadHandlers: handlers,
      templates,
      definitions,
    });
  }

  private markSaved(): void {
    this.snapshot.set(canonical(toDefinition(this.model()), this.status()));
    this.serverErrors.set([]);
  }

  // ------------------------------------------------------------ Ändern

  /** Jede Änderung blendet Meldungen aus (E-38). */
  update(change: (model: EditorModel) => EditorModel): void {
    if (this.readOnly()) {
      return;
    }
    this.message.set(null);
    this.model.update(change);
  }

  updateStep(name: string, change: (step: EditorStep) => EditorStep): void {
    this.update((m) => updateStep(m, name, change));
  }

  select(name: string | null): void {
    this.message.set(null);
    this.selected.set(name);
  }

  setStatus(status: DefinitionStatus): void {
    this.message.set(null);
    this.status.set(status);
  }

  add(kind: StepKind): void {
    const result = addStep(this.model(), kind);
    this.update(() => result.model);
    this.selected.set(result.name);
  }

  setKind(name: string, kind: StepKind): void {
    this.update((m) => setKind(m, name, kind));
  }

  /** @returns Fehlercode für das Feld oder null, wenn umbenannt. */
  rename(from: string, to: string): string | null {
    const error = nameError(this.model(), from, to);
    if (error !== null) {
      return error;
    }
    this.update((m) => renameStep(m, from, to));
    if (this.selected() === from) {
      this.selected.set(to.trim());
    }
    return null;
  }

  /** Löschen mit «Rückgängig» (9.14). */
  remove(name: string): void {
    const before = this.model();
    const selectedBefore = this.selected();
    const index = before.steps.findIndex((s) => s.name === name);
    if (index < 0 || this.readOnly()) {
      return;
    }
    this.update((m) => removeStep(m, index));
    if (this.selected() === name) {
      this.selected.set(orderedStepNames(this.model())[0] ?? null);
    }
    this.toasts.show({
      message: this.i18n.t('editor.step.deleted', { name }),
      actionLabel: this.i18n.t('action.undo'),
      onAction: () => {
        this.model.set(before);
        this.selected.set(selectedBefore);
      },
    });
  }

  // ------------------------------------------------------------ Ansicht

  setView(view: EditorView): void {
    if (view === this.view()) {
      return; // Klick auf die aktive Ansicht tut nichts (9.14).
    }
    if (view === 'json') {
      this.jsonText.set(JSON.stringify(toDefinition(this.model()), null, 2));
      this.message.set(null);
      this.view.set('json');
      return;
    }
    this.applyJson();
  }

  /** JSON in die visuelle Ansicht übernehmen; ungültiges JSON bleibt stehen (E-36). */
  applyJson(): boolean {
    const parsed = this.parseJson();
    if (parsed === null) {
      this.message.set({ tone: 'error', text: this.i18n.t('editor.message.jsonInvalidSwitch') });
      return false;
    }
    const model = fromDefinition(parsed);
    this.model.set(model);
    if (!model.steps.some((s) => s.name === this.selected())) {
      this.selected.set(orderedStepNames(model)[0] ?? null);
    }
    this.message.set(null);
    this.view.set('visual');
    return true;
  }

  private parseJson(): JsonObject | null {
    try {
      const value: unknown = JSON.parse(this.jsonText());
      return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as JsonObject)
        : null;
    } catch {
      return null;
    }
  }

  // ------------------------------------------------------------ Prüfen und Speichern

  private scheduleValidation(model: EditorModel): void {
    if (this.validateTimer) {
      clearTimeout(this.validateTimer);
    }
    this.validateTimer = setTimeout(() => {
      this.validateTimer = null;
      const definition = toDefinition({ ...model, id: model.id.trim() || 'neu' });
      this.api.definitions.validate(definition).subscribe({
        next: (result) => {
          if (this.model() === model) {
            this.serverErrors.set(result.errors);
          }
        },
        error: () => undefined, // Prüfung ist eine Hilfe; ohne Server bleibt die lokale.
      });
    }, VALIDATE_DEBOUNCE_MS);
  }

  /** Speichern; in der JSON-Ansicht der Text (E-37). @returns ob gespeichert wurde. */
  async save(): Promise<boolean> {
    if (this.readOnly() || this.busy()) {
      return false;
    }
    let definition: JsonObject;
    if (this.view() === 'json') {
      const parsed = this.parseJson();
      if (parsed === null) {
        this.message.set({ tone: 'error', text: this.i18n.t('editor.message.jsonInvalid') });
        return false;
      }
      definition = parsed;
    } else {
      definition = toDefinition(this.model());
    }
    const id = typeof definition['id'] === 'string' ? definition['id'].trim() : '';
    if (id === '') {
      this.message.set({ tone: 'error', text: this.i18n.t('editor.message.idMissing') });
      return false;
    }
    if (this.view() === 'visual' && this.status() === 'active' && this.errors().length > 0) {
      this.message.set({ tone: 'error', text: this.i18n.t('editor.issues.activeBlocked') });
      return false;
    }

    this.busy.set(true);
    try {
      const name =
        typeof definition['name'] === 'string' && definition['name'].trim() !== ''
          ? definition['name']
          : id;
      const saved = await firstValueFrom(
        this.api.definitions.save(id, { definition, name, status: this.status() }),
      );
      if (this.view() === 'json') {
        this.model.set(fromDefinition({ ...definition, id }));
        this.jsonText.set(JSON.stringify(toDefinition(this.model()), null, 2));
      } else {
        this.model.update((m) => ({ ...m, id, name }));
      }
      this.version.set(saved.version);
      this.status.set(saved.status);
      this.markSaved();
      this.serverErrors.set(saved.validation);
      this.message.set({
        tone: 'ok',
        text: this.i18n.t('editor.message.saved', {
          id,
          version: saved.version,
          status: this.i18n.t(`status.definition.${saved.status}`).toLocaleLowerCase('de'),
        }),
      });
      return true;
    } catch (error) {
      const apiError = WfApiError.from(error);
      if (apiError.code === 'invalid_definition') {
        this.serverErrors.set(apiError.validationErrors());
        this.message.set({
          tone: 'error',
          text:
            apiError
              .validationErrors()
              .map((e) => e.message)
              .join('; ') || apiError.message,
        });
      } else {
        this.message.set({ tone: 'error', text: this.errorText(apiError) });
      }
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  private errorText(error: WfApiError): string {
    const key = `error.${error.code}` as WfMessageKey;
    const known = this.i18n.tDynamic(key);
    return known !== key ? known : error.message || this.i18n.t('error.unknown');
  }
}
