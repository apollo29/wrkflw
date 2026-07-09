import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  CurrentStep,
  DefinitionSummary,
  WorkflowBuilderComponent,
  WorkflowRunnerComponent,
  WorkflowService,
  WorkflowTemplateManagerComponent,
} from '@apollo29/workflow-client';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [
    FormsModule,
    WorkflowRunnerComponent,
    WorkflowBuilderComponent,
    WorkflowTemplateManagerComponent,
  ],
  template: `
    <main class="demo">
      <h1>Workflow-Demo</h1>

      <nav class="tabs">
        <button type="button" [class.active]="view() === 'runner'" (click)="view.set('runner')">Runner</button>
        <button type="button" [class.active]="view() === 'editor'" (click)="view.set('editor')">Editor</button>
        <button type="button" [class.active]="view() === 'templates'" (click)="view.set('templates')">Templates</button>
      </nav>

      @if (view() === 'templates') {
        <wf-template-manager />
      } @else if (view() === 'runner') {
        @if (instanceId(); as id) {
          <p class="meta">
            Instanz: <code>{{ id }}</code>
            <button type="button" class="link" (click)="reset()">↺ anderen Workflow starten</button>
          </p>
          <wf-runner [instanceId]="id" (completed)="onCompleted($event)" />
          @if (finalStatus(); as status) {
            <p class="done">Workflow erreicht: <strong>{{ status }}</strong></p>
          }
        } @else {
          <div class="starter">
            <label>
              Workflow
              <select [ngModel]="selectedDef()" (ngModelChange)="selectedDef.set($event)" [disabled]="starting()">
                <option value="">— wählen —</option>
                @for (d of definitions(); track d.id) {
                  <option [value]="d.id">{{ d.name }} ({{ d.id }})</option>
                }
              </select>
            </label>
            <label>
              Start-Kontext (JSON)
              <textarea rows="5" [ngModel]="contextText()" (ngModelChange)="contextText.set($event)"
                        [disabled]="starting()" spellcheck="false"></textarea>
            </label>
            <button type="button" (click)="start()" [disabled]="starting() || selectedDef() === ''">
              {{ starting() ? 'Startet …' : 'Workflow starten' }}
            </button>
            @if (definitions().length === 0) {
              <p class="hint">Noch keine Definitionen — lege im Tab „Editor" einen Workflow an oder seede die Beispiele.</p>
            }
          </div>
          @if (error(); as err) {
            <p class="error">{{ err }}</p>
          }
        }
      } @else {
        <wf-builder />
      }
    </main>
  `,
  styles: [
    `
      .demo { max-width: 48rem; margin: 2rem auto; font-family: system-ui, sans-serif; }
      .tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
      .tabs .active { font-weight: bold; }
      .starter { display: flex; flex-direction: column; gap: 0.75rem; max-width: 32rem; }
      .starter label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.9rem; }
      .starter textarea { font-family: ui-monospace, monospace; font-size: 0.85rem; }
      .starter button { align-self: flex-start; }
      .meta { display: flex; align-items: center; gap: 0.75rem; }
      .link { background: none; border: none; color: #1d4ed8; cursor: pointer; padding: 0; text-decoration: underline; }
      .hint { color: #666; font-size: 0.85rem; }
      .error { color: #b00020; }
      .done { color: #0a7d28; }
    `,
  ],
})
export class AppComponent implements OnInit {
  private readonly service = inject(WorkflowService);

  readonly view = signal<'runner' | 'editor' | 'templates'>('runner');
  readonly definitions = signal<DefinitionSummary[]>([]);
  readonly selectedDef = signal<string>('');
  readonly contextText = signal<string>(
    '{\n  "name": "Mara",\n  "email": "mara@example.com",\n  "plan": "enterprise"\n}',
  );
  readonly instanceId = signal<string | null>(null);
  readonly starting = signal(false);
  readonly error = signal<string | null>(null);
  readonly finalStatus = signal<string | null>(null);

  ngOnInit(): void {
    this.loadDefinitions();
  }

  private loadDefinitions(): void {
    this.service.listDefinitions().subscribe({
      next: (res) => {
        this.definitions.set(res.definitions);
        if (this.selectedDef() === '' && res.definitions.length > 0) {
          // Onboarding bevorzugen, sonst die erste Definition.
          const onboarding = res.definitions.find((d) => d.id === 'onboarding');
          this.selectedDef.set((onboarding ?? res.definitions[0]).id);
        }
      },
      error: () => this.error.set('Definitionen konnten nicht geladen werden — läuft die API?'),
    });
  }

  start(): void {
    const definition = this.selectedDef();
    if (definition === '') {
      return;
    }

    let context: Record<string, unknown> = {};
    const raw = this.contextText().trim();
    if (raw !== '') {
      try {
        context = JSON.parse(raw) as Record<string, unknown>;
      } catch {
        this.error.set('Kontext ist kein gültiges JSON.');
        return;
      }
    }

    this.starting.set(true);
    this.error.set(null);
    this.finalStatus.set(null);
    this.service.start(definition, context).subscribe({
      next: (summary) => {
        this.starting.set(false);
        this.instanceId.set(summary.id);
      },
      error: () => {
        this.starting.set(false);
        this.error.set('Start fehlgeschlagen — läuft die API unter der konfigurierten URL?');
      },
    });
  }

  reset(): void {
    this.instanceId.set(null);
    this.finalStatus.set(null);
    this.error.set(null);
    this.loadDefinitions();
  }

  onCompleted(step: CurrentStep): void {
    this.finalStatus.set(step.status);
  }
}
