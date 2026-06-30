import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { DefinitionSummary } from './workflow.models';
import { WorkflowService } from './workflow.service';

const TEMPLATE = `{
  "startStep": "start",
  "steps": {
    "start": { "type": "automatic", "transitions": [] }
  }
}`;

/**
 * Einfacher Editor für Workflow-Definitionen: listet vorhandene Definitionen,
 * lädt eine zum Bearbeiten, validiert das JSON lokal und speichert eine neue
 * Version über die API. Validierungsfehler der Engine (HTTP 400) werden angezeigt.
 */
@Component({
  selector: 'wf-editor',
  standalone: true,
  imports: [FormsModule],
  template: `
    <section class="wf-editor">
      <div class="wf-editor__list">
        <h3>Definitionen</h3>
        <button type="button" (click)="newDefinition()">Neu</button>
        <ul>
          @for (def of definitions(); track def.id + ':' + def.version) {
            <li>
              <button type="button" (click)="selectDefinition(def.id)">
                {{ def.id }} v{{ def.version }} @if (def.active) { · aktiv }
              </button>
            </li>
          } @empty {
            <li class="wf-editor__empty">Noch keine Definitionen.</li>
          }
        </ul>
      </div>

      <form class="wf-editor__form" (ngSubmit)="save()">
        <label>ID
          <input type="text" name="id" [ngModel]="idText()" (ngModelChange)="idText.set($event)" />
        </label>
        <label>Name
          <input type="text" name="name" [ngModel]="nameText()" (ngModelChange)="nameText.set($event)" />
        </label>
        <label>Definition (JSON)
          <textarea name="json" rows="14" [ngModel]="jsonText()" (ngModelChange)="jsonText.set($event)"></textarea>
        </label>
        <button type="submit" [disabled]="busy()">Speichern</button>

        @if (message(); as msg) {
          <p class="wf-editor__ok">{{ msg }}</p>
        }
        @if (error(); as err) {
          <p class="wf-editor__error" role="alert">{{ err }}</p>
        }
      </form>
    </section>
  `,
  styles: [
    `
      .wf-editor { display: flex; gap: 1.5rem; align-items: flex-start; }
      .wf-editor__form { display: flex; flex-direction: column; gap: 0.5rem; flex: 1; }
      .wf-editor__form textarea { font-family: monospace; width: 100%; }
      .wf-editor__error { color: #b00020; }
      .wf-editor__ok { color: #0a7d28; }
    `,
  ],
})
export class WorkflowEditorComponent implements OnInit {
  private readonly service = inject(WorkflowService);

  readonly definitions = signal<DefinitionSummary[]>([]);
  readonly idText = signal('');
  readonly nameText = signal('');
  readonly jsonText = signal(TEMPLATE);
  readonly busy = signal(false);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);

  ngOnInit(): void {
    this.loadList();
  }

  loadList(): void {
    this.service.listDefinitions().subscribe({
      next: (res) => this.definitions.set(res.definitions),
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  newDefinition(): void {
    this.idText.set('');
    this.nameText.set('');
    this.jsonText.set(TEMPLATE);
    this.message.set(null);
    this.error.set(null);
  }

  selectDefinition(id: string): void {
    this.message.set(null);
    this.error.set(null);
    this.service.getDefinition(id).subscribe({
      next: (res) => {
        this.idText.set(res.id);
        const summary = this.definitions().find((d) => d.id === id);
        this.nameText.set(summary?.name ?? id);
        this.jsonText.set(JSON.stringify(res.definition, null, 2));
      },
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  save(): void {
    this.message.set(null);
    this.error.set(null);

    if (this.idText().trim() === '') {
      this.error.set('Bitte eine ID angeben.');
      return;
    }

    let definition: Record<string, unknown>;
    try {
      definition = JSON.parse(this.jsonText()) as Record<string, unknown>;
    } catch {
      this.error.set('Ungültiges JSON.');
      return;
    }

    this.busy.set(true);
    this.service.saveDefinition(this.idText().trim(), this.nameText().trim(), definition).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.message.set(`Gespeichert: ${res.id} v${res.version}.`);
        this.loadList();
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.error.set(this.apiError(err));
      },
    });
  }

  private apiError(err: unknown): string {
    if (err instanceof HttpErrorResponse) {
      const body = err.error as { error?: { message?: string } } | null;
      if (body?.error?.message) {
        return body.error.message;
      }
      return `HTTP ${err.status}`;
    }
    return 'Unbekannter Fehler';
  }
}
