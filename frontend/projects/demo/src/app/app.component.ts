import { Component, inject, signal } from '@angular/core';
import {
  CurrentStep,
  WorkflowEditorComponent,
  WorkflowRunnerComponent,
  WorkflowService,
} from 'workflow-client';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [WorkflowRunnerComponent, WorkflowEditorComponent],
  template: `
    <main class="demo">
      <h1>Workflow-Demo</h1>

      <nav class="tabs">
        <button type="button" [class.active]="view() === 'runner'" (click)="view.set('runner')">Runner</button>
        <button type="button" [class.active]="view() === 'editor'" (click)="view.set('editor')">Editor</button>
      </nav>

      @if (view() === 'runner') {
        @if (instanceId(); as id) {
          <p class="meta">Instanz: <code>{{ id }}</code></p>
          <wf-runner [instanceId]="id" (completed)="onCompleted($event)" />
          @if (finalStatus(); as status) {
            <p class="done">Workflow erreicht: <strong>{{ status }}</strong></p>
          }
        } @else {
          <button type="button" (click)="start()" [disabled]="starting()">
            {{ starting() ? 'Startet …' : 'Onboarding starten' }}
          </button>
          @if (error(); as err) {
            <p class="error">{{ err }}</p>
          }
        }
      } @else {
        <wf-editor />
      }
    </main>
  `,
  styles: [
    `
      .demo { max-width: 48rem; margin: 2rem auto; font-family: system-ui, sans-serif; }
      .tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; }
      .tabs .active { font-weight: bold; }
      .error { color: #b00020; }
      .done { color: #0a7d28; }
    `,
  ],
})
export class AppComponent {
  private readonly service = inject(WorkflowService);

  readonly view = signal<'runner' | 'editor'>('runner');
  readonly instanceId = signal<string | null>(null);
  readonly starting = signal(false);
  readonly error = signal<string | null>(null);
  readonly finalStatus = signal<string | null>(null);

  start(): void {
    this.starting.set(true);
    this.error.set(null);
    this.service
      .start('onboarding', { name: 'Mara', email: 'mara@example.com', plan: 'enterprise' })
      .subscribe({
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

  onCompleted(step: CurrentStep): void {
    this.finalStatus.set(step.status);
  }
}
