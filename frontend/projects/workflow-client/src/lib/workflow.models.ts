/** Status einer Workflow-Instanz (entspricht dem Backend). */
export type WorkflowStatus =
  | 'running'
  | 'waiting_event'
  | 'waiting_timer'
  | 'completed'
  | 'failed'
  /**
   * Abgebrochen: jemand ist aus diesem Ablauf herausgegangen, bevor er zu
   * Ende war — der Rueckschritt ueber eine Ablauf-Grenze hinweg macht einen
   * gerade begonnenen Kind-Ablauf gegenstandslos.
   */
  | 'cancelled';

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
  /** Nur bei type 'file': welche Prüfung die Host-App auf die Datei anwendet. */
  handler?: string;
}

/** UI-Beschreibung eines interaktiven Schritts (aus der Definition). */
export interface StepUi {
  title?: string;
  description?: string;
  fields?: UiField[];
  events?: string[];
  /** Optionale Referenz auf eine 'page'-Vorlage, die der Runner anzeigt. */
  templateId?: string;
  /** Aufschrift je Ereignis; ohne Eintrag steht der rohe Ereignisname auf dem Knopf. */
  eventLabels?: Record<string, string>;
  /** Sichtbarkeit auf einer öffentlichen Seite; die Host-App wertet sie aus. */
  public?: boolean;
  /**
   * Darf man von diesem Schritt aus einen Schritt zurück? Die Engine geht dabei
   * zum letzten Eingabeschritt und notfalls über die Ablauf-Grenze hinweg.
   */
  back?: boolean;
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
  /**
   * Ob es von hier aus einen Schritt zurückgeht. Entscheidet die Engine — eine
   * Oberfläche soll keinen Knopf zeigen, den sie anschliessend abweist.
   */
  canGoBack?: boolean;
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
  /** Alle Durchläufe dieser Version — auch abgeschlossene und abgebrochene. */
  instances: number;
  /** Davon die noch laufenden. */
  runningInstances: number;
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
/**
 * Eine Datei-Prüfung, die die Host-App auf ein `file`-Feld anwenden kann
 * (GET /upload-handlers). Die Engine kennt die Werte nicht — sie fragt danach.
 */
export interface UploadHandlerEntry {
  key: string;
  label: string;
  description: string;
}

export interface UploadHandlerCatalogResponse {
  handlers: UploadHandlerEntry[];
}

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
