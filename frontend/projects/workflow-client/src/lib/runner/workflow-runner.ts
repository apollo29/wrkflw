import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  Injector,
  input,
  output,
  signal,
  untracked,
} from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { WfApiError } from '../api/api-error';
import type { CurrentStep } from '../api/models';
import { WorkflowApi } from '../api/workflow-api';
import { WF_CONFIG } from '../config/config';
import { WfI18n, WfTranslatePipe } from '../i18n/i18n';
import { WfBanner } from '../ui/banner';
import { WfButton } from '../ui/button';
import { WfField } from '../ui/field';
import { instanceStatusPill, WfPill } from '../ui/pill';
import {
  displayValue,
  initialValues,
  newEventKey,
  parseStepUi,
  payloadFor,
  renderTemplate,
  RunnerField,
  runnerState,
} from './runner-model';

/** Abfrage, solange der Durchlauf im Hintergrund läuft. */
export const RUNNER_POLL_MS = 800;
export const RUNNER_POLL_LIMIT_MS = 120_000;

interface FileState {
  readonly status: 'checking' | 'done' | 'rejected';
  readonly name?: string;
  readonly message?: string;
}

/**
 * Generische Darstellung eines Durchlaufs (F-50, F-85): der interaktive Schritt mit
 * Seitenvorlage, Feldern und einem Knopf je Ereignis, sonst Warte- und Endzustände.
 * Ereignisse tragen einen Idempotenz-Schlüssel (Q-04).
 */
@Component({
  selector: 'wf-workflow-runner',
  imports: [WfBanner, WfButton, WfField, WfPill, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-run' },
  template: `
    <div class="wf-run__live wf-sr-only" aria-live="polite">{{ liveText() }}</div>
    @if (error(); as e) {
      <wf-banner tone="error" data-testid="runner-error">
        {{ 'runner.error' | wfT: { message: e } }}
        <button type="button" wfButton variant="text" size="sm" (click)="retry()">
          {{ 'runner.retry' | wfT }}
        </button>
      </wf-banner>
    }
    @switch (state()) {
      @case ('loading') {
        <section class="wf-zone wf-run__card" aria-busy="true">
          <p class="wf-muted">{{ 'runner.loading' | wfT }}</p>
        </section>
      }
      @case ('interactive') {
        @let s = step()!;
        @let u = ui();
        <form
          class="wf-zone wf-run__card wf-run__card--interactive"
          (submit)="$event.preventDefault()"
          data-testid="runner-form"
        >
          <header class="wf-run__head">
            <span class="wf-lbl">{{ 'runner.interactive' | wfT }}</span>
            <h2 tabindex="-1" class="wf-run__title" #title>{{ u.title || s.step }}</h2>
            @if (u.description) {
              <p class="wf-run__desc">{{ u.description }}</p>
            }
          </header>
          @if (pageHtml(); as html) {
            <div class="wf-run__page" [innerHTML]="html" data-testid="runner-page"></div>
          }
          @for (f of u.fields; track f.name) {
            @switch (f.type) {
              @case ('display') {
                <div class="wf-run__display">
                  <span class="wf-field-label">{{ f.label }}</span>
                  @let v = shown(f);
                  <span data-testid="runner-display">{{
                    v === true
                      ? ('runner.yes' | wfT)
                      : v === false
                        ? ('runner.no' | wfT)
                        : (v ?? ('runner.empty' | wfT))
                  }}</span>
                </div>
              }
              @case ('boolean') {
                <label class="wf-ed-label-inline wf-run__check">
                  <input
                    type="checkbox"
                    class="wf-chk"
                    [checked]="values()[f.name] === true"
                    [disabled]="busy()"
                    (change)="set(f.name, $any($event.target).checked)"
                  />
                  {{ f.label }}
                </label>
              }
              @case ('file') {
                @let fs = files()[f.name];
                <wf-field
                  [label]="f.label"
                  [hint]="fileHint(f)"
                  [error]="fs?.status === 'rejected' ? fs.message : null"
                >
                  <input
                    type="file"
                    class="wf-inp wf-run__file"
                    [disabled]="busy() || !canUpload || fs?.status === 'checking'"
                    (change)="upload(f, $any($event.target))"
                    [attr.data-field]="f.name"
                  />
                </wf-field>
              }
              @default {
                <wf-field [label]="f.label">
                  <input
                    class="wf-inp"
                    [value]="values()[f.name] ?? ''"
                    [disabled]="busy()"
                    (input)="set(f.name, $any($event.target).value)"
                    [attr.data-field]="f.name"
                  />
                </wf-field>
              }
            }
          }
          <div class="wf-run__actions">
            @for (e of s.events; track e.event; let first = $first) {
              <button
                type="button"
                wfButton
                [variant]="first ? 'primary' : 'default'"
                [disabled]="busy()"
                [busy]="busy() && sending() === e.event"
                (click)="send(e.event)"
                [attr.data-event]="e.event"
              >
                {{ e.label }}
              </button>
            }
            @if (s.canGoBack) {
              <span class="wf-ed-spacer"></span>
              <button
                type="button"
                wfButton
                variant="text"
                [disabled]="busy()"
                (click)="back()"
                data-testid="runner-back"
              >
                {{ 'runner.back' | wfT }}
              </button>
            }
          </div>
        </form>
      }
      @default {
        @let s = step()!;
        @let pill = statusPill(s.status);
        <section
          class="wf-zone wf-run__card"
          [attr.data-state]="state()"
          data-testid="runner-state"
        >
          <header class="wf-run__head">
            <wf-pill [kind]="pill.kind" [dot]="true">{{ pill.label | wfT }}</wf-pill>
            <h2 tabindex="-1" class="wf-run__title" #title>{{ ui().title || s.step }}</h2>
          </header>
          <p>{{ stateText() }}</p>
          @if (state() !== 'finished') {
            <div class="wf-run__actions">
              <button type="button" wfButton (click)="refresh()" data-testid="runner-refresh">
                {{ 'runner.refresh' | wfT }}
              </button>
            </div>
          }
        </section>
      }
    }
  `,
})
export class WfWorkflowRunner {
  readonly instanceId = input.required<string>();
  /** Der Durchlauf ist beendet (abgeschlossen, fehlgeschlagen oder abgebrochen). */
  readonly finished = output<CurrentStep>();
  /** Ein neuer Schritt ist da. */
  readonly stepChange = output<CurrentStep>();

  private readonly api = inject(WorkflowApi);
  private readonly i18n = inject(WfI18n);
  private readonly config = inject(WF_CONFIG, { optional: true });
  private readonly injector = inject(Injector);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);

  protected readonly step = signal<CurrentStep | null>(null);
  protected readonly error = signal<string | null>(null);
  protected readonly busy = signal(false);
  protected readonly sending = signal<string | null>(null);
  protected readonly values = signal<Record<string, string | boolean>>({});
  protected readonly files = signal<Record<string, FileState>>({});
  protected readonly pageHtml = signal<string | null>(null);
  protected readonly stillRunning = signal(false);
  protected readonly statusPill = instanceStatusPill;
  protected readonly canUpload = this.config?.uploadFile != null;

  protected readonly state = computed(() => runnerState(this.step()));
  protected readonly ui = computed(() => parseStepUi(this.step()?.ui));

  protected readonly stateText = computed(() => {
    const s = this.step();
    if (!s) {
      return '';
    }
    if (s.finished) {
      return this.i18n.tDynamic(`status.instance.finishedText.${s.status}`);
    }
    if (s.status === 'running' && this.stillRunning()) {
      return this.i18n.t('runner.stillRunning');
    }
    if (s.status === 'waiting_event' && !s.interactive) {
      return this.i18n.t('runner.waitingSub');
    }
    return this.i18n.tDynamic(`status.instance.waitingText.${s.status}`);
  });

  protected readonly liveText = computed(() => {
    const s = this.step();
    return s
      ? `${this.ui().title || s.step}: ${this.i18n.tDynamic(`status.instance.${s.status}`)}`
      : '';
  });

  /** Schlüssel des noch nicht bestätigten Ereignisses (Wiederholung nach Netzfehler). */
  private pending: { event: string; key: string; step: string } | null = null;
  private pollTimer: ReturnType<typeof setTimeout> | null = null;
  private pollStarted = 0;
  private readonly templates = new Map<string, string>();
  private destroyed = false;

  constructor() {
    effect(() => {
      const id = this.instanceId();
      untracked(() => {
        this.step.set(null);
        this.pending = null;
        void this.refresh(id);
      });
    });
    inject(DestroyRef).onDestroy(() => {
      this.destroyed = true;
      this.stopPolling();
    });
  }

  protected shown(f: RunnerField): string | boolean | null {
    return displayValue(this.step()?.context[f.name]);
  }

  protected fileHint(f: RunnerField): string | null {
    if (!this.canUpload) {
      return this.i18n.t('runner.file.unavailable');
    }
    const fs = this.files()[f.name];
    if (fs?.status === 'checking') {
      return this.i18n.t('runner.file.checking');
    }
    if (fs?.status === 'done') {
      return this.i18n.t('runner.file.done', { name: fs.name ?? '' });
    }
    return typeof this.values()[f.name] === 'string' && this.values()[f.name] !== ''
      ? this.i18n.t('runner.file.stored')
      : null;
  }

  protected set(name: string, value: string | boolean): void {
    this.values.update((v) => ({ ...v, [name]: value }));
  }

  async refresh(id = this.instanceId()): Promise<void> {
    this.stopPolling();
    try {
      const step = await firstValueFrom(this.api.instances.currentStep(id));
      if (!this.destroyed && id === this.instanceId()) {
        this.error.set(null);
        this.apply(step);
      }
    } catch (error) {
      this.error.set(this.errorText(error));
    }
  }

  protected retry(): void {
    this.error.set(null);
    if (this.pending && this.step()?.step === this.pending.step) {
      void this.send(this.pending.event);
    } else {
      void this.refresh();
    }
  }

  private apply(step: CurrentStep): void {
    const previous = this.step();
    const changed = !previous || previous.step !== step.step || previous.status !== step.status;
    this.step.set(step);
    if (changed) {
      this.values.set(initialValues(parseStepUi(step.ui).fields, step.context));
      this.files.set({});
      this.stepChange.emit(step);
      void this.loadTemplate(step);
      this.focusTitle();
    }
    if (step.finished) {
      this.finished.emit(step);
      return;
    }
    if (step.status === 'running') {
      if (this.pollStarted === 0) {
        this.pollStarted = Date.now();
      }
      if (Date.now() - this.pollStarted < RUNNER_POLL_LIMIT_MS) {
        this.stillRunning.set(false);
        this.pollTimer = setTimeout(() => void this.refresh(), RUNNER_POLL_MS);
      } else {
        this.stillRunning.set(true);
        this.pollStarted = 0;
      }
    } else {
      this.pollStarted = 0;
    }
  }

  private stopPolling(): void {
    if (this.pollTimer) {
      clearTimeout(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private async loadTemplate(step: CurrentStep): Promise<void> {
    const id = parseStepUi(step.ui).template;
    if (!id || !step.interactive) {
      this.pageHtml.set(null);
      return;
    }
    try {
      let body = this.templates.get(id);
      if (body === undefined) {
        body = (await firstValueFrom(this.api.templates.get(id))).body;
        this.templates.set(id, body);
      }
      if (this.step() === step) {
        this.pageHtml.set(renderTemplate(body, step.context));
      }
    } catch {
      this.pageHtml.set(null); // Ohne Vorlage bleibt der Schritt bedienbar.
    }
  }

  /** Ereignis senden; ein Doppelklick wirkt einmal (Q-04). */
  async send(event: string): Promise<void> {
    const step = this.step();
    if (!step || this.busy()) {
      return;
    }
    const key =
      this.pending && this.pending.event === event && this.pending.step === step.step
        ? this.pending.key
        : newEventKey();
    this.pending = { event, key, step: step.step };
    this.busy.set(true);
    this.sending.set(event);
    this.error.set(null);
    try {
      await firstValueFrom(
        this.api.instances.sendEvent(
          step.instanceId,
          event,
          payloadFor(this.ui().fields, this.values()),
          key,
        ),
      );
      this.pending = null;
      await this.refresh(step.instanceId);
    } catch (error) {
      const apiError = WfApiError.from(error);
      if (apiError.code !== 'network') {
        this.pending = null; // Abgelehnt: ein neuer Versuch ist ein neues Ereignis.
      }
      this.error.set(this.errorText(apiError));
    } finally {
      this.busy.set(false);
      this.sending.set(null);
    }
  }

  async back(): Promise<void> {
    const step = this.step();
    if (!step || this.busy() || !step.canGoBack) {
      return;
    }
    this.busy.set(true);
    try {
      await firstValueFrom(this.api.instances.back(step.instanceId));
      await this.refresh(step.instanceId);
    } catch (error) {
      this.error.set(this.errorText(error));
    } finally {
      this.busy.set(false);
    }
  }

  /** Upload über die Host-App (F-54); eine Ablehnung lässt den Schritt offen. */
  async upload(field: RunnerField, inputEl: HTMLInputElement): Promise<void> {
    const file = inputEl.files?.[0];
    const uploadFile = this.config?.uploadFile;
    const step = this.step();
    if (!file || !uploadFile || !step) {
      return;
    }
    this.files.update((f) => ({ ...f, [field.name]: { status: 'checking', name: file.name } }));
    try {
      const result = await uploadFile(file, {
        handler: field.handler,
        instanceId: step.instanceId,
        step: step.step,
        field: field.name,
      });
      if ('value' in result) {
        this.set(field.name, result.value);
        this.files.update((f) => ({ ...f, [field.name]: { status: 'done', name: file.name } }));
      } else {
        this.set(field.name, '');
        this.files.update((f) => ({
          ...f,
          [field.name]: { status: 'rejected', message: result.rejected },
        }));
        inputEl.value = '';
      }
    } catch {
      this.set(field.name, '');
      this.files.update((f) => ({
        ...f,
        [field.name]: { status: 'rejected', message: this.i18n.t('runner.file.failed') },
      }));
      inputEl.value = '';
    }
  }

  private focusTitle(): void {
    afterNextRender(
      () =>
        this.host.nativeElement
          .querySelector<HTMLElement>('.wf-run__title')
          ?.focus({ preventScroll: true }),
      {
        injector: this.injector,
      },
    );
  }

  private errorText(error: unknown): string {
    const e = WfApiError.from(error);
    const key = `error.${e.code}`;
    const known = this.i18n.tDynamic(key);
    return known !== key ? known : e.message || this.i18n.t('error.unknown');
  }
}
