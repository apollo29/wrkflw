import { httpResource, HttpResourceRef } from '@angular/common/http';
import { inject, Injectable, Signal } from '@angular/core';
import { WorkflowApi } from './workflow-api';
import type {
  ActionInfo,
  DefinitionSummary,
  InstanceFilter,
  InstanceList,
  ModelInfo,
  TemplateSummary,
  TemplateType,
} from './models';

/**
 * Lesende Listen als Signals (`httpResource`). Aufruf in einem Injection-Kontext, z. B.
 * im Feld einer Komponente: `readonly definitions = inject(WorkflowResources).definitions();`
 */
@Injectable({ providedIn: 'root' })
export class WorkflowResources {
  private readonly api = inject(WorkflowApi);

  definitions(): HttpResourceRef<{ definitions: DefinitionSummary[] } | undefined> {
    return httpResource(() => ({ url: this.api.url('/workflows'), headers: this.api.headers() }));
  }

  instances(filter: Signal<InstanceFilter>): HttpResourceRef<InstanceList | undefined> {
    return httpResource(() => {
      const params: Record<string, string> = {};
      for (const [key, value] of Object.entries(filter())) {
        if (value !== undefined && value !== '') {
          params[key] = String(value);
        }
      }
      return { url: this.api.url('/instances'), headers: this.api.headers(), params };
    });
  }

  templates(
    type?: Signal<TemplateType | undefined>,
  ): HttpResourceRef<{ templates: TemplateSummary[] } | undefined> {
    return httpResource(() => {
      const t = type?.();
      const params: Record<string, string> = t ? { type: t } : {};
      return { url: this.api.url('/templates'), headers: this.api.headers(), params };
    });
  }

  dataModels(writable = false): HttpResourceRef<{ models: ModelInfo[] } | undefined> {
    const params: Record<string, string> = writable ? { writable: '1' } : {};
    return httpResource(() => ({
      url: this.api.url('/data-models'),
      headers: this.api.headers(),
      params,
    }));
  }

  actions(): HttpResourceRef<{ actions: ActionInfo[] } | undefined> {
    return httpResource(() => ({ url: this.api.url('/actions'), headers: this.api.headers() }));
  }
}
