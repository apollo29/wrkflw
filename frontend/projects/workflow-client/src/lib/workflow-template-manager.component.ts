import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HtmlEditorComponent } from './html-editor.component';
import { TemplateSummary, TemplateUsageEntry } from './workflow.models';
import { WorkflowService } from './workflow.service';

/**
 * Verwaltung wiederverwendbarer Templates: Liste, Bearbeiten (Name, Betreff, HTML-Body
 * über den WYSIWYG-Editor) und Speichern. Workflow-Schritte referenzieren ein Template
 * über seine ID (send_email → config.templateId).
 */
@Component({
  selector: 'wf-template-manager',
  standalone: true,
  imports: [FormsModule, HtmlEditorComponent],
  template: `
    <div class="wf-tpl">
      <div class="wf-tpl__list">
        <div class="wf-tpl__label">Templates</div>
        <button type="button" (click)="newTemplate()">+ Neu</button>
        <ul>
          @for (t of templates(); track t.id) {
            <li>
              <button type="button" (click)="select(t.id)">{{ t.name }} <small>({{ t.id }})</small></button>
            </li>
          } @empty {
            <li class="wf-tpl__empty">Noch keine Templates.</li>
          }
        </ul>
      </div>

      <div class="wf-tpl__form">
        <label>ID
          <input type="text" [ngModel]="idText()" (ngModelChange)="idText.set($event)" placeholder="welcome" />
        </label>
        <label>Name
          <input type="text" [ngModel]="nameText()" (ngModelChange)="nameText.set($event)" />
        </label>
        <label>Betreff
          <input type="text" [ngModel]="subjectText()" (ngModelChange)="subjectText.set($event)" placeholder="Hallo {{ '{{name}}' }}" />
        </label>
        <label>Inhalt (HTML)</label>
        <wf-html-editor
          [placeholders]="placeholders"
          [value]="bodyText()"
          (valueChange)="bodyText.set($event)"
        ></wf-html-editor>

        <div class="wf-tpl__actions">
          <button type="button" (click)="save()" [disabled]="busy()">Speichern</button>
          @if (idText().trim() !== '') {
            <button type="button" class="wf-tpl__delete" (click)="remove()" [disabled]="busy()">Löschen</button>
          }
        </div>
        @if (message(); as msg) { <p class="wf-tpl__ok">{{ msg }}</p> }
        @if (error(); as err) { <p class="wf-tpl__error" role="alert">{{ err }}</p> }

        @if (usageLoaded()) {
          <div class="wf-tpl__usage">
            @if (usage().length > 0) {
              <div class="wf-tpl__label">Verwendet in {{ usage().length }} Schritt(en):</div>
              <ul>
                @for (u of usage(); track u.definitionId + '/' + u.version + '/' + u.step) {
                  <li>{{ u.definitionId }} <small>v{{ u.version }}</small> → {{ u.step }}</li>
                }
              </ul>
            } @else {
              <div class="wf-tpl__label">Wird derzeit von keinem Workflow verwendet.</div>
            }
          </div>
        }
      </div>
    </div>
  `,
  styles: [
    `
      .wf-tpl { display: flex; gap: 1.25rem; align-items: flex-start; }
      .wf-tpl__list { min-width: 180px; }
      .wf-tpl__list ul { list-style: none; padding: 0; margin: 8px 0; display: flex; flex-direction: column; gap: 4px; }
      .wf-tpl__list button { width: 100%; text-align: left; }
      .wf-tpl__form { flex: 1; display: flex; flex-direction: column; gap: 8px; }
      .wf-tpl__form label { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
      .wf-tpl__label { font-size: 12px; color: #666; }
      .wf-tpl__ok { color: #0a7d28; }
      .wf-tpl__error { color: #b00020; }
      .wf-tpl__actions { display: flex; gap: 8px; }
      .wf-tpl__delete { color: #b00020; }
      .wf-tpl__usage { border-top: 1px solid #eee; padding-top: 8px; }
      .wf-tpl__usage ul { list-style: none; padding: 0; margin: 4px 0; font-size: 13px; }
      .wf-tpl__usage small { color: #999; }
    `,
  ],
})
export class WorkflowTemplateManagerComponent implements OnInit {
  private readonly service = inject(WorkflowService);

  readonly placeholders = ['name', 'email', 'firma', 'datum'];

  readonly templates = signal<TemplateSummary[]>([]);
  readonly idText = signal('');
  readonly nameText = signal('');
  readonly subjectText = signal('');
  readonly bodyText = signal('');
  readonly busy = signal(false);
  readonly message = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly usage = signal<TemplateUsageEntry[]>([]);
  readonly usageLoaded = signal(false);

  ngOnInit(): void {
    this.loadList();
  }

  loadList(): void {
    this.service.listTemplates().subscribe({
      next: (res) => this.templates.set(res.templates),
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  newTemplate(): void {
    this.idText.set('');
    this.nameText.set('');
    this.subjectText.set('');
    this.bodyText.set('');
    this.message.set(null);
    this.error.set(null);
    this.usage.set([]);
    this.usageLoaded.set(false);
  }

  select(id: string): void {
    this.message.set(null);
    this.error.set(null);
    this.service.getTemplate(id).subscribe({
      next: (t) => {
        this.idText.set(t.id);
        this.nameText.set(t.name);
        this.subjectText.set(t.subject);
        this.bodyText.set(t.body);
      },
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
    this.loadUsage(id);
  }

  private loadUsage(id: string): void {
    this.usage.set([]);
    this.usageLoaded.set(false);
    this.service.templateUsage(id).subscribe({
      next: (res) => {
        this.usage.set(res.usage);
        this.usageLoaded.set(true);
      },
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  remove(): void {
    const id = this.idText().trim();
    if (id === '') {
      return;
    }
    const inUse = this.usage().length;
    const note = inUse > 0 ? ` Es wird noch von ${inUse} Schritt(en) referenziert.` : '';
    if (!confirm(`Template "${id}" wirklich löschen?${note}`)) {
      return;
    }

    this.message.set(null);
    this.error.set(null);
    this.busy.set(true);
    this.service.deleteTemplate(id).subscribe({
      next: () => {
        this.busy.set(false);
        this.newTemplate();
        this.message.set(`Gelöscht: ${id}.`);
        this.loadList();
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.error.set(this.apiError(err));
      },
    });
  }

  save(): void {
    this.message.set(null);
    this.error.set(null);

    const id = this.idText().trim();
    if (id === '') {
      this.error.set('Bitte eine ID angeben.');
      return;
    }

    this.busy.set(true);
    this.service.saveTemplate(id, this.nameText().trim() || id, this.subjectText(), this.bodyText()).subscribe({
      next: (res) => {
        this.busy.set(false);
        this.message.set(`Gespeichert: ${res.id}.`);
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
