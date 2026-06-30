import { Component, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CurrentStep } from './workflow.models';
import { WorkflowService } from './workflow.service';

/**
 * Rendert einen interaktiven Schritt generisch aus `ui.fields`, sendet Events und
 * pollt automatisch, solange der Workflow im Hintergrund läuft (Status `running`).
 * Zeigt Lade-, Fehler- und Abschluss-Zustände an.
 *
 *   <wf-runner [instanceId]="id" (completed)="onDone($event)" />
 */
@Component({
  selector: 'wf-runner',
  standalone: true,
  imports: [FormsModule],
  template: `
    @if (error(); as err) {
      <div class="wf-error" role="alert">Fehler: {{ err }}</div>
    } @else {
      @if (step(); as s) {
        @if (s.finished) {
          <div class="wf-done">Workflow abgeschlossen (Status: {{ s.status }}).</div>
        } @else if (s.interactive) {
          <form class="wf-form" (ngSubmit)="$event.preventDefault()">
            @if (s.ui.title) {
              <h3 class="wf-title">{{ s.ui.title }}</h3>
            }
            @if (s.ui.description) {
              <p class="wf-desc">{{ s.ui.description }}</p>
            }
            @for (field of s.ui.fields ?? []; track field.name) {
              <label class="wf-field">
                <span>{{ field.label ?? field.name }}</span>
                @if (field.type === 'boolean') {
                  <input
                    type="checkbox"
                    [name]="field.name"
                    [ngModel]="boolValue(field.name)"
                    (ngModelChange)="setValue(field.name, $event)"
                  />
                } @else {
                  <input
                    type="text"
                    [name]="field.name"
                    [ngModel]="stringValue(field.name)"
                    (ngModelChange)="setValue(field.name, $event)"
                  />
                }
              </label>
            }
            <div class="wf-actions">
              @for (event of s.events; track event) {
                <button type="submit" [disabled]="busy()" (click)="submit(event)">{{ event }}</button>
              }
            </div>
          </form>
        } @else {
          <div class="wf-waiting">Im Hintergrund … (Status: {{ s.status }})</div>
        }
      } @else {
        <div class="wf-loading">Lädt …</div>
      }
    }
  `,
  styles: [
    `
      .wf-error { color: #b00020; }
      .wf-field { display: block; margin: 0.5rem 0; }
      .wf-actions { margin-top: 0.75rem; display: flex; gap: 0.5rem; }
    `,
  ],
})
export class WorkflowRunnerComponent implements OnInit {
  readonly instanceId = input.required<string>();
  readonly completed = output<CurrentStep>();

  private readonly service = inject(WorkflowService);

  readonly step = signal<CurrentStep | null>(null);
  readonly error = signal<string | null>(null);
  readonly busy = signal<boolean>(false);

  private readonly values = signal<Record<string, unknown>>({});
  private polling = false;

  ngOnInit(): void {
    this.refresh(this.instanceId());
  }

  setValue(name: string, value: unknown): void {
    this.values.update((current) => ({ ...current, [name]: value }));
  }

  stringValue(name: string): string {
    const value = this.values()[name];
    return value === undefined || value === null ? '' : String(value);
  }

  boolValue(name: string): boolean {
    return this.values()[name] === true;
  }

  submit(event: string): void {
    const id = this.instanceId();
    this.busy.set(true);
    this.service.sendEvent(id, event, this.values()).subscribe({
      next: () => {
        this.busy.set(false);
        this.values.set({});
        this.refresh(id);
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.error.set(this.message(err));
      },
    });
  }

  private refresh(id: string): void {
    this.busy.set(true);
    this.service.currentStep(id).subscribe({
      next: (s) => {
        this.busy.set(false);
        this.applyStep(id, s);
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.error.set(this.message(err));
      },
    });
  }

  private applyStep(id: string, s: CurrentStep): void {
    this.step.set(s);
    if (s.finished) {
      this.completed.emit(s);
      return;
    }
    // Solange der Workflow im Hintergrund läuft: weiter pollen, bis er anhält.
    if (s.status === 'running' && !this.polling) {
      this.poll(id);
    }
  }

  private poll(id: string): void {
    this.polling = true;
    setTimeout(() => {
      this.service.currentStep(id).subscribe({
        next: (s) => {
          this.step.set(s);
          if (s.finished) {
            this.polling = false;
            this.completed.emit(s);
          } else if (s.status === 'running') {
            this.poll(id);
          } else {
            this.polling = false;
          }
        },
        error: (err: unknown) => {
          this.polling = false;
          this.error.set(this.message(err));
        },
      });
    }, 800);
  }

  private message(err: unknown): string {
    if (err instanceof Error) {
      return err.message;
    }
    return 'Unbekannter Fehler';
  }
}
