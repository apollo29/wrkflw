import { HttpErrorResponse } from '@angular/common/http';
import { Component, inject, OnInit, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HtmlEditorComponent } from './html-editor.component';
import { TemplateSummary, TemplateType, TemplateUsageEntry } from './workflow.models';
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
        <div class="wf-tpl__seg" role="group" aria-label="Vorlagen-Typ">
          <button type="button" class="wf-tpl__seg-btn" [class.is-active]="filterType() === 'email'" (click)="setFilter('email')">E-Mail</button>
          <button type="button" class="wf-tpl__seg-btn" [class.is-active]="filterType() === 'page'" (click)="setFilter('page')">Seite</button>
        </div>
        <div class="wf-tpl__items">
          @for (t of templates(); track t.id) {
            <button type="button" class="wf-tpl__item" [class.is-active]="t.id === idText()" (click)="select(t.id)">
              <span class="wf-tpl__item-name">{{ t.name }}</span>
              <span class="wf-tpl__item-id">{{ t.id }}</span>
            </button>
          } @empty {
            <div class="wf-tpl__empty">Noch keine Vorlagen.</div>
          }
        </div>
        <button type="button" class="wf-tpl__add" (click)="newTemplate()">+ Vorlage</button>
      </div>

      <div class="wf-tpl__form">
        <div class="wf-tpl__grid">
          <label class="wf-tpl__field">
            <span class="wf-tpl__label">ID</span>
            <input type="text" class="wf-tpl__mono" [ngModel]="idText()" (ngModelChange)="idText.set($event)" placeholder="welcome" />
          </label>
          <label class="wf-tpl__field">
            <span class="wf-tpl__label">Name</span>
            <input type="text" [ngModel]="nameText()" (ngModelChange)="nameText.set($event)" />
          </label>
        </div>
        @if (filterType() === 'email') {
          <label class="wf-tpl__field">
            <span class="wf-tpl__label">Betreff</span>
            <input type="text" [ngModel]="subjectText()" (ngModelChange)="subjectText.set($event)" placeholder="Hallo {{ '{{name}}' }}" />
          </label>
        }
        <label class="wf-tpl__field">
          <span class="wf-tpl__label">{{ filterType() === 'page' ? 'Seiteninhalt (HTML)' : 'Inhalt (HTML)' }}</span>
        </label>
        <wf-html-editor
          [placeholders]="placeholders"
          [value]="bodyText()"
          (valueChange)="bodyText.set($event)"
        ></wf-html-editor>

        @if (usageLoaded()) {
          <div class="wf-tpl__usage">
            @if (usage().length > 0) {
              <span class="wf-tpl__usage-lead">Verwendet in {{ usage().length }} Schritt(en):</span>
              @for (u of usage(); track u.definitionId + '/' + u.version + '/' + u.step; let last = $last) {
                <span class="wf-tpl__usage-item">{{ u.definitionId }} <small>v{{ u.version }}</small> / {{ u.step }}</span>
                @if (!last) { <span aria-hidden="true">·</span> }
              }
            } @else {
              <span class="wf-tpl__usage-lead">Wird derzeit von keinem Workflow verwendet.</span>
            }
          </div>
        }

        <div class="wf-tpl__footer">
          @if (message(); as msg) { <p class="wf-tpl__ok">{{ msg }}</p> }
          @if (error(); as err) { <p class="wf-tpl__error" role="alert">{{ err }}</p> }
          <span class="wf-tpl__spacer"></span>
          <div class="wf-tpl__actions">
            @if (idText().trim() !== '') {
              <button type="button" class="wf-tpl__delete" (click)="remove()" [disabled]="busy()">Löschen</button>
            }
            <button type="button" class="wf-tpl__save" (click)="save()" [disabled]="busy()">Speichern</button>
          </div>
        </div>
      </div>
    </div>
  `,
  styleUrls: ['./workflow-theme.css', './workflow-template-manager.component.css'],
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
  readonly filterType = signal<TemplateType>('email');

  ngOnInit(): void {
    this.loadList();
  }

  loadList(): void {
    this.service.listTemplates(this.filterType()).subscribe({
      next: (res) => this.templates.set(res.templates),
      error: (err: unknown) => this.error.set(this.apiError(err)),
    });
  }

  /** Wechselt zwischen E-Mail- und Seiten-Vorlagen und lädt die Liste neu. */
  setFilter(type: TemplateType): void {
    if (this.filterType() === type) {
      return;
    }
    this.filterType.set(type);
    this.newTemplate();
    this.loadList();
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
        this.filterType.set(t.type);
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
    this.service
      .saveTemplate(id, this.nameText().trim() || id, this.subjectText(), this.bodyText(), this.filterType())
      .subscribe({
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
