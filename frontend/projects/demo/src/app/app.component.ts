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
  host: { '[attr.data-theme]': 'theme()' },
  imports: [
    FormsModule,
    WorkflowRunnerComponent,
    WorkflowBuilderComponent,
    WorkflowTemplateManagerComponent,
  ],
  template: `
    <main class="demo">
      <header class="demo__head">
        <div>
          <div class="demo__eyebrow">Workflow-Engine · Admin</div>
          <h1 class="demo__title">Visueller Workflow-Builder</h1>
        </div>
        <div class="demo__theme" role="group" aria-label="Darstellung">
          <button type="button" [class.active]="theme() === 'hell'" (click)="theme.set('hell')">Hell</button>
          <button type="button" [class.active]="theme() === 'dunkel'" (click)="theme.set('dunkel')">Dunkel</button>
        </div>
      </header>

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
      :host {
        display: block;
        min-height: 100vh;
        background: #f4f4f5;
        color: #18181b;
        font-family: 'IBM Plex Sans', system-ui, sans-serif;
      }
      :host([data-theme='dunkel']) { background: #0b0b0d; color: #ececf0; }

      .demo { max-width: 1180px; margin: 0 auto; padding: 2.5rem 1.5rem 5rem; }
      .demo__head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
      .demo__eyebrow { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; color: #71717a; }
      :host([data-theme='dunkel']) .demo__eyebrow { color: #9a9aa6; }
      .demo__title { margin: 4px 0 0; font-size: 26px; font-weight: 600; letter-spacing: -0.02em; }

      .demo__theme { display: flex; gap: 2px; padding: 3px; border-radius: 9px; background: #fff; border: 1px solid #e5e5ea; }
      :host([data-theme='dunkel']) .demo__theme { background: #151518; border-color: #2a2a31; }
      .demo__theme button { padding: 5px 14px; border: 0; border-radius: 6px; background: transparent; color: #71717a; font: inherit; font-size: 12.5px; cursor: pointer; }
      .demo__theme button.active { background: #4338ca; color: #fff; font-weight: 500; }

      .tabs { display: flex; gap: 0.5rem; margin-bottom: 1.25rem; }
      .tabs button { padding: 6px 14px; border: 1px solid #e5e5ea; border-radius: 8px; background: #fff; color: #18181b; font: inherit; font-size: 13px; cursor: pointer; }
      :host([data-theme='dunkel']) .tabs button { background: #151518; border-color: #2a2a31; color: #ececf0; }
      .tabs button.active { border-color: #4338ca; color: #4338ca; font-weight: 600; }
      :host([data-theme='dunkel']) .tabs button.active { color: #a5b4fc; border-color: #6d63f0; }

      .starter { display: flex; flex-direction: column; gap: 0.75rem; max-width: 32rem; }
      .starter label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.9rem; }
      .starter textarea { font-family: 'IBM Plex Mono', ui-monospace, monospace; font-size: 0.85rem; }
      .starter button { align-self: flex-start; }
      .meta { display: flex; align-items: center; gap: 0.75rem; }
      .link { background: none; border: none; color: #4338ca; cursor: pointer; padding: 0; text-decoration: underline; }
      :host([data-theme='dunkel']) .link { color: #a5b4fc; }
      .hint { color: #71717a; font-size: 0.85rem; }
      .error { color: #b91c1c; }
      .done { color: #047857; }
    `,
  ],
})
export class AppComponent implements OnInit {
  private readonly service = inject(WorkflowService);

  readonly view = signal<'runner' | 'editor' | 'templates'>('runner');
  readonly theme = signal<'hell' | 'dunkel'>('hell');
  readonly definitions = signal<DefinitionSummary[]>([]);
  readonly selectedDef = signal<string>('');
  readonly contextText = signal<string>(
    '{\n  "name": "Mara",\n  "email": "mara@example.com",\n  "plan": "enterprise",\n  "orderId": 1\n}',
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
