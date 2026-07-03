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

/** Kurzeintrag einer Definition-Version (GET /workflows). */
export interface DefinitionSummary {
  id: string;
  version: number;
  name: string;
  active: boolean;
}

export interface DefinitionListResponse {
  definitions: DefinitionSummary[];
}

/** Die aktive Definition als Objekt (GET /workflows/{id}). */
export interface DefinitionResponse {
  id: string;
  definition: Record<string, unknown>;
}

/** Antwort beim Anlegen einer neuen Version (POST /workflows/{id}). */
export interface SaveDefinitionResponse {
  id: string;
  version: number;
  active: boolean;
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
