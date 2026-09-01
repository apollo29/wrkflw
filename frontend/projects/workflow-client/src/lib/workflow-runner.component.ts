import { Component, inject, input, OnInit, output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { CurrentStep } from './workflow.models';
import {
  workflowFinishedText,
  workflowStatusLabel,
  workflowWaitingText,
} from './workflow.status';
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
      <div class="wf-card wf-state wf-state--err" role="alert">
        <span class="wf-state__label">Fehler</span>
        <div class="wf-state__row">
          <span class="wf-dot" aria-hidden="true"></span>
          <span>Fehler: {{ err }}</span>
        </div>
        <button type="button" class="wf-btn" (click)="retry()">Erneut versuchen</button>
      </div>
    } @else {
      @if (step(); as s) {
        @if (s.finished) {
          <div class="wf-card wf-state wf-state--ok">
            <span class="wf-state__label">Abgeschlossen</span>
            <div class="wf-state__row">
              <span class="wf-dot" aria-hidden="true"></span>
              <span>{{ finishedText(s.status) }}</span>
            </div>
          </div>
        } @else if (s.interactive) {
          <form class="wf-card wf-form" (ngSubmit)="$event.preventDefault()">
            <div class="wf-head">
              <span class="wf-head__label">Interaktiver Schritt</span>
              @if (s.ui.title) {
                <h3 class="wf-title">{{ s.ui.title }}</h3>
              }
              @if (s.ui.description) {
                <p class="wf-desc">{{ s.ui.description }}</p>
              }
            </div>
            <div class="wf-body">
              @if (pageHtml(); as html) {
                <div class="wf-page" [innerHTML]="html"></div>
              }
              @for (field of s.ui.fields ?? []; track field.name) {
                @if (field.type === 'boolean') {
                  <label class="wf-check">
                    <input
                      type="checkbox"
                      [name]="field.name"
                      [ngModel]="boolValue(field.name)"
                      (ngModelChange)="setValue(field.name, $event)"
                    />
                    <span>{{ field.label ?? field.name }}</span>
                  </label>
                } @else if (field.type === 'display') {
                  <!--
                    Anzeigefeld: zeigt einen Wert, den ein vorheriger Schritt
                    in den Kontext geschrieben hat (etwa ein Datencheck). Es
                    nimmt nichts entgegen und schickt nichts mit — sonst waere
                    der Name, unter dem ein Wert angezeigt wird, zugleich der
                    Name, unter dem er sich ueberschreiben liesse.
                  -->
                  <div class="wf-field wf-field--display">
                    <span class="wf-field__label">{{ field.label ?? field.name }}</span>
                    <span class="wf-field__value">{{ anzeigewert(s, field.name) }}</span>
                  </div>
                } @else if (field.type === 'file') {
                  <!--
                    Dateien nimmt dieser Runner nicht entgegen: die Engine-API
                    kennt nur JSON-Events, der Upload-Weg gehoert der Host-App
                    und ihrer eigenen Seite. Ein Dateifeld hier anzubieten,
                    das nichts hochlaedt, waere die schlechtere Antwort.
                  -->
                  <div class="wf-field wf-field--file">
                    <span class="wf-field__label">{{ field.label ?? field.name }}</span>
                    <span class="wf-field__note">Datei-Upload — nur über die Seite der Anwendung.</span>
                  </div>
                } @else {
                  <label class="wf-field">
                    <span class="wf-field__label">{{ field.label ?? field.name }}</span>
                    <input
                      type="text"
                      [name]="field.name"
                      [ngModel]="stringValue(field.name)"
                      (ngModelChange)="setValue(field.name, $event)"
                    />
                  </label>
                }
              }
              <div class="wf-actions">
                @for (event of s.events; track event; let first = $first) {
                  <button type="submit" class="wf-btn" [class.wf-btn--primary]="first"
                          [disabled]="busy()" (click)="submit(event)">{{ eventLabel(event) }}</button>
                }
              </div>
            </div>
          </form>
        } @else {
          <div class="wf-card wf-state wf-state--auto">
            <span class="wf-state__label">{{ statusLabel(s.status) }}</span>
            <div class="wf-state__row">
              <span class="wf-dot" aria-hidden="true"></span>
              <span>{{ waitingText(s.status) }}</span>
            </div>
          </div>
        }
      } @else {
        <div class="wf-card wf-loading">
          <span class="wf-state__label">Lädt</span>
          <span class="wf-skel wf-skel--sm"></span>
          <span class="wf-skel"></span>
          <span class="wf-skel wf-skel--block"></span>
        </div>
      }
    }
  `,
  styleUrls: ['./workflow-theme.css', './workflow-runner.component.css'],
})
export class WorkflowRunnerComponent implements OnInit {
  readonly instanceId = input.required<string>();
  readonly completed = output<CurrentStep>();

  private readonly service = inject(WorkflowService);

  readonly step = signal<CurrentStep | null>(null);
  readonly error = signal<string | null>(null);
  readonly busy = signal<boolean>(false);
  /** Gerenderter HTML-Body der Seitenvorlage (ui.templateId), sonst null. */
  readonly pageHtml = signal<string | null>(null);

  private readonly values = signal<Record<string, unknown>>({});
  private readonly pageCache: Record<string, string> = {};
  private polling = false;

  ngOnInit(): void {
    this.refresh(this.instanceId());
  }

  /** Lädt den aktuellen Schritt nach einem Fehler erneut. */
  retry(): void {
    this.error.set(null);
    this.refresh(this.instanceId());
  }

  /**
   * Lesbare Texte statt des rohen Status — siehe workflow.status.ts.
   *
   * Die Kopfzeile der Karte nennt den Zustand in zwei Worten, der Satz darunter
   * den Grund. Beide kommen aus derselben Quelle, damit sie nicht auseinander
   * laufen.
   */
  statusLabel(status: string): string {
    return workflowStatusLabel(status);
  }

  waitingText(status: string): string {
    return workflowWaitingText(status);
  }

  finishedText(status: string): string {
    return workflowFinishedText(status);
  }

  setValue(name: string, value: unknown): void {
    this.values.update((current) => ({ ...current, [name]: value }));
  }

  /**
   * Die Aufschrift des Knopfes: was die Definition unter `ui.eventLabels`
   * hinterlegt hat, sonst der rohe Ereignisname.
   *
   * Vorher stand hier IMMER der rohe Name. Bei `submit` faellt das nicht auf,
   * bei allem anderen schon — ein zweiter Ausgang «ich komme nicht weiter»
   * hiesse sonst «hilfe».
   */
  eventLabel(event: string): string {
    const label = this.step()?.ui.eventLabels?.[event] ?? '';

    return label.trim() !== '' ? label : event;
  }

  stringValue(name: string): string {
    const value = this.values()[name];
    return value === undefined || value === null ? '' : String(value);
  }

  /**
   * Der anzuzeigende Wert eines `display`-Feldes: aus dem Kontext des
   * Schritts, nicht aus dem Formular.
   *
   * Ein leerer Kontextwert bekommt einen Strich statt einer leeren Zeile —
   * sonst sähe «nichts geladen» aus wie «kein Feld».
   */
  anzeigewert(step: CurrentStep, name: string): string {
    const value = step.context?.[name];
    if (value === undefined || value === null || value === '') {
      return '—';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
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
    this.updatePage(s);
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
          this.updatePage(s);
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

  /**
   * Lädt und rendert die Seitenvorlage (ui.templateId) eines interaktiven Schritts;
   * ersetzt {{platzhalter}} aus dem Kontext. Ohne Vorlage wird nichts angezeigt.
   */
  private updatePage(s: CurrentStep): void {
    const id = s.interactive ? s.ui.templateId : undefined;
    if (!id) {
      this.pageHtml.set(null);
      return;
    }
    const cached = this.pageCache[id];
    if (cached !== undefined) {
      this.pageHtml.set(this.interpolate(cached, s.context));
      return;
    }
    this.service.getTemplate(id).subscribe({
      next: (t) => {
        this.pageCache[id] = t.body;
        this.pageHtml.set(this.interpolate(t.body, s.context));
      },
      error: () => this.pageHtml.set(null),
    });
  }

  private interpolate(template: string, context: Record<string, unknown>): string {
    return template.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_match, key: string) => {
      const value = context[key];
      return value === undefined || value === null ? '' : String(value);
    });
  }

  private message(err: unknown): string {
    if (err instanceof Error) {
      return err.message;
    }
    return 'Unbekannter Fehler';
  }
}
