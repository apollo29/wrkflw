import { Component, inject, signal } from '@angular/core';
import { CurrentStep, WorkflowRunnerComponent, WorkflowService } from 'workflow-client';

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [WorkflowRunnerComponent],
  template: `
    <main class="demo">
      <h1>Workflow-Demo — Onboarding</h1>

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
    </main>
  `,
  styles: [
    `
      .demo { max-width: 40rem; margin: 2rem auto; font-family: system-ui, sans-serif; }
      .error { color: #b00020; }
      .done { color: #0a7d28; }
    `,
  ],
})
export class AppComponent {
  private readonly service = inject(WorkflowService);

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
