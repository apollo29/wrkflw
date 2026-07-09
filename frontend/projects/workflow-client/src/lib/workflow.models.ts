/** Status einer Workflow-Instanz (entspricht dem Backend). */
export type WorkflowStatus =
  | 'running'
  | 'waiting_event'
  | 'waiting_timer'
  | 'completed'
  | 'failed';

export type StepType = 'automatic' | 'interactive' | 'timer';

/** Kurzantwort bei start() und sendEvent(). */
export interface InstanceSummary {
  id: string;
  status: WorkflowStatus;
  currentStep: string;
}

/** Vollständiger Instanz-Zustand (GET /instances/{id}). */
export interface InstanceState {
  id: string;
  status: WorkflowStatus;
  currentStep: string;
  context: Record<string, unknown>;
  lastError: string | null;
}

/** Ein generisch zu renderndes Eingabefeld eines interaktiven Schritts. */
export interface UiField {
  name: string;
  label?: string;
  type?: string;
}

/** UI-Beschreibung eines interaktiven Schritts (aus der Definition). */
export interface StepUi {
  title?: string;
  description?: string;
  fields?: UiField[];
  events?: string[];
  /** Optionale Referenz auf eine 'page'-Vorlage, die der Runner anzeigt. */
  templateId?: string;
}

/** Aktueller Schritt inkl. UI-Metadaten (GET /instances/{id}/current-step). */
export interface CurrentStep {
  instanceId: string;
  status: WorkflowStatus;
  step: string;
  type: StepType;
  interactive: boolean;
  finished: boolean;
  ui: StepUi;
  events: string[];
  context: Record<string, unknown>;
}

/** Ein History-Eintrag (GET /instances/{id}/history). */
export interface HistoryEntry {
  kind: string;
  step: string | null;
  detail: Record<string, unknown>;
  createdAt: string;
}

export interface HistoryResponse {
  instanceId: string;
  history: HistoryEntry[];
}

/** Lebenszyklus einer Workflow-Definition. */
export type WorkflowLifecycle = 'active' | 'inactive' | 'draft';

/** Kurzeintrag einer Definition-Version (GET /workflows). */
export interface DefinitionSummary {
  id: string;
  version: number;
  name: string;
  active: boolean;
  status: WorkflowLifecycle;
}

export interface DefinitionListResponse {
  definitions: DefinitionSummary[];
}

/** Die aktive Definition als Objekt (GET /workflows/{id}). */
export interface DefinitionResponse {
  id: string;
  definition: Record<string, unknown>;
}

/** Antwort beim Speichern (POST /workflows/{id}). */
export interface SaveDefinitionResponse {
  id: string;
  version: number;
  active: boolean;
  status: WorkflowLifecycle;
}

/** Ein Config-Feld einer Action (aus dem Action-Katalog). */
export interface ActionField {
  name: string;
  label: string;
  type: string;
}

/** Ein Eintrag im Action-Katalog (GET /actions). */
export interface ActionCatalogEntry {
  key: string;
  label?: string;
  config: ActionField[];
}

export interface ActionCatalogResponse {
  actions: ActionCatalogEntry[];
}

/** Eine abfragbare Entität/Tabelle mit ihren Feldern (GET /data-catalog). */
export interface DataCatalogEntry {
  entity: string;
  label: string;
  fields: string[];
}

export interface DataCatalogResponse {
  entities: DataCatalogEntry[];
}

/** Template-Typ: E-Mail (send_email) oder Seite (interaktiver Schritt). */
export type TemplateType = 'email' | 'page';

/** Kurzeintrag eines wiederverwendbaren Templates (GET /templates). */
export interface TemplateSummary {
  id: string;
  name: string;
  type: TemplateType;
}

export interface TemplateListResponse {
  templates: TemplateSummary[];
}

/** Vollständiges Template (GET /templates/{id}). */
export interface TemplateDetail {
  id: string;
  name: string;
  type: TemplateType;
  subject: string;
  body: string;
}

/** Ein Schritt, der ein Template referenziert (GET /templates/{id}/usage). */
export interface TemplateUsageEntry {
  definitionId: string;
  version: number;
  step: string;
}

export interface TemplateUsageResponse {
  templateId: string;
  usage: TemplateUsageEntry[];
}
