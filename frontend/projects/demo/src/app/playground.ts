import { Component, computed, inject, signal } from '@angular/core';
import {
  JsonObject,
  WfApiError,
  WfBanner,
  WfButton,
  WfField,
  WfThemeService,
  WfToastHost,
  WfWorkflowRunner,
  WorkflowApi,
  WorkflowResources,
} from '@apollo29/workflow-client';
import { firstValueFrom } from 'rxjs';

interface InputSpec {
  readonly name: string;
  readonly label: string;
  readonly required: boolean;
  readonly example: string;
}

/** Demo-Seite: einen Workflow starten und mit dem Runner durchspielen (Demo, nicht Library). */
@Component({
  selector: 'demo-playground',
  imports: [WfBanner, WfButton, WfField, WfToastHost, WfWorkflowRunner],
  host: { class: 'wf', '[attr.data-theme]': 'theme.theme()' },
  template: `
    <div class="playground">
      <h1>Durchspielen</h1>
      @if (instanceId(); as id) {
        <div class="wf-ed-row">
          <span class="wf-muted wf-mono">{{ id }}</span>
          <button
            type="button"
            wfButton
            variant="text"
            (click)="reset()"
            data-testid="playground-reset"
          >
            Neu starten
          </button>
        </div>
        <wf-workflow-runner [instanceId]="id" (finished)="done.set(true)" />
      } @else {
        <section class="wf-zone playground__start">
          <wf-field label="Workflow">
            <select
              class="wf-inp"
              (change)="choose($any($event.target).value)"
              data-testid="playground-workflow"
            >
              <option value="">— Workflow wählen —</option>
              @for (d of active(); track d.id) {
                <option [value]="d.id">{{ d.name }} ({{ d.id }})</option>
              }
            </select>
          </wf-field>
          @for (input of inputs(); track input.name) {
            <wf-field
              [label]="input.label + (input.required ? ' *' : '')"
              [hint]="input.example ? 'Beispiel: ' + input.example : null"
            >
              <input
                class="wf-inp"
                [value]="values()[input.name] ?? ''"
                (input)="set(input.name, $any($event.target).value)"
                [attr.data-input]="input.name"
              />
            </wf-field>
          }
          @if (error(); as e) {
            <wf-banner tone="error">{{ e }}</wf-banner>
          }
          <div>
            <button
              type="button"
              wfButton
              variant="primary"
              [disabled]="!workflow()"
              (click)="start()"
              data-testid="playground-start"
            >
              Starten
            </button>
          </div>
        </section>
      }
    </div>
    <wf-toast-host />
  `,
  styles: `
    :host {
      display: block;
      min-height: calc(100vh - 48px);
    }
    .playground {
      max-width: 760px;
      margin: 0 auto;
      padding: 24px 28px 48px;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .playground__start {
      display: flex;
      flex-direction: column;
      gap: 14px;
    }
    @container wf (max-width: 767px) {
      .playground {
        padding: 20px 16px 40px;
      }
    }
  `,
})
export class Playground {
  protected readonly theme = inject(WfThemeService);
  private readonly api = inject(WorkflowApi);
  private readonly definitions = inject(WorkflowResources).definitions();

  protected readonly workflow = signal<string>('');
  protected readonly inputs = signal<InputSpec[]>([]);
  protected readonly values = signal<Record<string, string>>({});
  protected readonly instanceId = signal<string | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly done = signal(false);
  protected readonly active = computed(() =>
    (this.definitions.hasValue() ? (this.definitions.value()?.definitions ?? []) : []).filter(
      (d) => d.status === 'active',
    ),
  );

  protected async choose(id: string): Promise<void> {
    this.workflow.set(id);
    this.error.set(null);
    if (!id) {
      this.inputs.set([]);
      return;
    }
    const loaded = await firstValueFrom(this.api.definitions.get(id));
    const raw = Array.isArray(loaded.definition['inputs'])
      ? (loaded.definition['inputs'] as JsonObject[])
      : [];
    const inputs = raw.map((i) => ({
      name: String(i['name'] ?? ''),
      label: String(i['label'] ?? i['name'] ?? ''),
      required: i['required'] === true,
      example: String(i['beispiel'] ?? ''),
    }));
    this.inputs.set(inputs);
    this.values.set(Object.fromEntries(inputs.map((i) => [i.name, i.example])));
  }

  protected set(name: string, value: string): void {
    this.values.update((v) => ({ ...v, [name]: value }));
  }

  protected async start(): Promise<void> {
    this.error.set(null);
    try {
      const started = await firstValueFrom(
        this.api.instances.start(this.workflow(), { context: this.values(), subjectType: 'demo' }),
      );
      this.instanceId.set(started.id);
    } catch (e) {
      this.error.set(WfApiError.from(e).message);
    }
  }

  protected reset(): void {
    this.instanceId.set(null);
    this.done.set(false);
  }
}
