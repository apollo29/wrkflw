/*
 * Typen der HTTP-API v3 (docs/openapi.yaml). Von Hand gepflegt; ein Test im Backend
 * stellt sicher, dass die Routen mit der Dokumentation übereinstimmen.
 */

export type InstanceStatus =
  'running' | 'waiting_event' | 'waiting_timer' | 'completed' | 'failed' | 'cancelled';
export type DefinitionStatus = 'active' | 'inactive' | 'draft';
export type TemplateType = 'email' | 'page';
export type StepType = 'automatic' | 'interactive' | 'timer';
export type AuthType = 'none' | 'bearer' | 'api_key' | 'basic';
export type FieldType = 'text' | 'number' | 'bool' | 'date' | 'datetime';
export type JsonObject = Record<string, unknown>;

// ------------------------------------------------------------------ Instanzen

export interface InstanceBrief {
  readonly id: string;
  readonly status: InstanceStatus;
  readonly currentStep: string;
}

export interface Instance extends InstanceBrief {
  readonly definitionId: string;
  readonly definitionVersion: number;
  readonly subjectType: string | null;
  readonly subjectId: string | null;
  readonly wakeAt: string | null;
  readonly context: JsonObject;
  readonly lastError: string | null;
}

export interface InstanceSummary {
  readonly id: string;
  readonly definitionId: string;
  readonly definitionVersion: number;
  readonly definitionName: string;
  readonly currentStep: string;
  readonly status: InstanceStatus;
  readonly subjectType: string | null;
  readonly subjectId: string | null;
  readonly wakeAt: string | null;
  readonly lastError: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}

export interface InstanceFilter {
  readonly status?: InstanceStatus;
  readonly definition?: string;
  readonly subjectType?: string;
  readonly subjectId?: string;
  readonly limit?: number;
  readonly offset?: number;
}

export interface InstanceList {
  readonly instances: readonly InstanceSummary[];
  readonly counts: Readonly<Record<InstanceStatus, number>>;
}

export interface StepEvent {
  readonly event: string;
  readonly label: string;
}

export interface CurrentStep {
  readonly instanceId: string;
  readonly status: InstanceStatus;
  readonly step: string;
  readonly type: StepType;
  readonly interactive: boolean;
  readonly finished: boolean;
  readonly ui: JsonObject;
  readonly events: readonly StepEvent[];
  readonly canGoBack: boolean;
  readonly context: JsonObject;
}

export interface HistoryEntry {
  readonly kind: string;
  readonly step: string | null;
  readonly detail: JsonObject;
  readonly at: string;
}

export interface StartRequest {
  readonly context?: JsonObject;
  readonly subjectType?: string;
  readonly subjectId?: string;
}

// ------------------------------------------------------------------ Definitionen

export interface StructureTransition {
  readonly to: string;
  readonly conditional: boolean;
  readonly event: string | null;
}

export interface DefinitionStructure {
  readonly startStep: string;
  readonly steps: readonly {
    readonly name: string;
    readonly kind: string;
    readonly transitions: readonly StructureTransition[];
  }[];
}

export interface DefinitionSummary {
  readonly id: string;
  readonly name: string;
  readonly version: number;
  readonly status: DefinitionStatus;
  readonly updatedAt: string;
  readonly runningInstances: number;
  readonly totalInstances: number;
  readonly olderVersions: number;
  readonly structure: DefinitionStructure | null;
  /** Laufende Durchläufe auf älteren Versionen (E-66); fehlt bei älteren Engines. */
  readonly olderRunning?: readonly { readonly version: number; readonly running: number }[];
}

export interface DefinitionVersion {
  readonly id: string;
  readonly version: number;
  readonly status: DefinitionStatus;
  readonly readOnly: boolean;
  readonly updatedAt: string;
  readonly definition: JsonObject;
}

export interface VersionSummary {
  readonly version: number;
  readonly name: string;
  readonly status: DefinitionStatus;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly runningInstances: number;
  readonly totalInstances: number;
  readonly deletable: boolean;
  readonly deleteBlockedBy: 'latest' | 'referenced' | null;
}

export interface ValidationError {
  readonly code: string;
  readonly message: string;
  readonly step: string | null;
  readonly transition: number | null;
}

export interface SaveDefinitionRequest {
  readonly definition: JsonObject;
  readonly name?: string;
  readonly status?: DefinitionStatus;
}

export interface SavedDefinition {
  readonly id: string;
  readonly version: number;
  readonly status: DefinitionStatus;
  readonly newVersion: boolean;
  readonly validation: readonly ValidationError[];
}

export interface ValidationResult {
  readonly valid: boolean;
  readonly errors: readonly ValidationError[];
}

// ------------------------------------------------------------------ Kataloge

export interface ActionInfo {
  readonly key: string;
  readonly label: string;
  readonly fields: readonly {
    readonly name: string;
    readonly label: string;
    readonly type: string;
  }[];
}

export interface ModelInfo {
  readonly name: string;
  readonly label: string;
  readonly idField: string;
  readonly fields: readonly {
    readonly name: string;
    readonly label: string;
    readonly type: FieldType;
    readonly writable: boolean;
  }[];
  readonly operations: {
    readonly load: boolean;
    readonly find: boolean;
    readonly update: boolean;
    readonly create: boolean;
  };
}

export interface UploadHandler {
  readonly key: string;
  readonly label: string;
  readonly description: string;
}

// ------------------------------------------------------------------ Vorlagen

export interface TemplateSummary {
  readonly id: string;
  readonly name: string;
  readonly type: TemplateType;
}

export interface Template extends TemplateSummary {
  readonly subject: string;
  readonly body: string;
}

export interface Usage {
  readonly definitionId: string;
  readonly version: number;
  readonly step: string;
}

// ------------------------------------------------------------------ Datenquellen

export interface Connection {
  readonly id: string;
  readonly name: string;
  readonly baseUrl: string;
  readonly authType: AuthType;
  readonly authHeader: string | null;
  readonly secretStored: boolean;
}

export interface ConnectionInput {
  readonly id?: string;
  readonly name: string;
  readonly baseUrl: string;
  readonly authType: AuthType;
  readonly authHeader?: string;
  /** Nur senden, nie anzeigen (F-135). */
  readonly secret?: string;
}

export interface ConnectionTest {
  readonly ok: boolean;
  readonly status: number | null;
  readonly durationMs: number;
  readonly message: string | null;
}

export interface RestOperation {
  readonly method?: string;
  readonly path: string;
}

export interface RestModel {
  readonly name: string;
  readonly label: string;
  readonly connection: string;
  readonly idField?: string;
  readonly listPath?: string;
  readonly subset?: Readonly<Record<string, string>>;
  readonly operations: Partial<Record<'load' | 'find' | 'update' | 'create', RestOperation>>;
  readonly fields: readonly {
    readonly name: string;
    readonly label?: string;
    readonly type?: FieldType;
    readonly path?: string;
    readonly writable?: boolean;
  }[];
}

export interface SampleResult {
  readonly status: number;
  readonly tree: unknown;
  readonly paths: readonly {
    readonly path: string;
    readonly type: string;
    readonly sample: string | number | boolean | null;
  }[];
}

export interface ModelUsage {
  readonly name: string;
  readonly definitions: readonly (Usage & { readonly fields: readonly string[] })[];
  readonly triggers: readonly string[];
}

export interface TriggerCondition {
  readonly field: string;
  readonly operator: string;
  readonly value: string | number | boolean | null;
}

export interface Trigger {
  readonly id: string;
  readonly name: string;
  readonly model: string;
  readonly conditions: readonly TriggerCondition[];
  readonly definitionId: string;
  readonly inputs: Readonly<Record<string, string>>;
  readonly intervalMinutes: number;
  readonly lockDays: number;
  readonly active: boolean;
  readonly lastPolledAt?: string | null;
}

export interface AuditEntry {
  readonly at: string;
  readonly actor: { readonly id: string; readonly name: string };
  readonly action: string;
  readonly objectType: string;
  readonly objectId: string;
  readonly detail: JsonObject;
}

// ------------------------------------------------------------------ Fehler

export interface ApiErrorBody {
  readonly error: {
    readonly code: string;
    readonly message: string;
    readonly details?: {
      readonly fields?: Readonly<Record<string, readonly string[]>>;
      readonly missing?: readonly string[];
      readonly errors?: readonly ValidationError[];
    } & JsonObject;
  };
}
