import { ChangeDetectionStrategy, Component, computed, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import type { AuthType, Connection, ConnectionTest } from '../api/models';
import { WfTranslatePipe } from '../i18n/i18n';
import { WfBanner } from '../ui/banner';
import { WfButton } from '../ui/button';
import { WfField } from '../ui/field';
import { WfPill } from '../ui/pill';
import {
  AUTH_TYPES,
  ConnectionDraft,
  connectionDraft,
  enteredSecret,
  toConnectionInput,
  validateConnection,
} from './data-source-forms';
import { SourceListItem, WfDsAudit, WfDsList, WfSourceEditorBase } from './source-parts';

/** Beim Ändern gehen Zugangsdaten nur über «Ersetzen» (eigener Aufruf, protokolliert). */
function withoutSecret<T extends { secret?: string }>(input: T): Omit<T, 'secret'> {
  const { secret: _secret, ...rest } = input;
  return rest;
}

/** Bereich «Verbindungen» (Board «Verbindung», F-133 bis F-139). */
@Component({
  selector: 'wf-connection-editor',
  imports: [WfBanner, WfButton, WfDsAudit, WfDsList, WfField, WfPill, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-g12 wf-ds' },
  template: `
    <wf-ds-list
      area="verbindungen"
      [label]="'sources.area.connections' | wfT"
      [items]="items()"
      [selected]="isNew() ? null : selectedId()"
      [empty]="'sources.connection.empty' | wfT"
      [newLabel]="'sources.connection.new' | wfT"
      (open)="open($event)"
      (create)="startNew()"
      [switchTo]="switchAreaFn"
    />
    <section
      class="wf-zone wf-c-9 wf-c-md-8 wf-c-sm-12 wf-ds__form"
      [attr.aria-label]="'sources.connection.label' | wfT: { id: draft().id }"
    >
      @if (message(); as m) {
        <wf-banner [tone]="m.tone" data-testid="ds-message">{{ m.text }}</wf-banner>
      }
      @if (!editing()) {
        <p class="wf-muted">{{ 'sources.none' | wfT }}</p>
      } @else {
        @if (dirty()) {
          <wf-pill kind="timer" [dot]="true" data-testid="ds-dirty">{{
            'state.unsaved' | wfT
          }}</wf-pill>
        }
        <div class="wf-g12">
          <wf-field
            class="wf-c-6 wf-c-sm-12"
            [label]="'sources.id' | wfT"
            [hint]="'sources.id.fixed' | wfT"
            [error]="err('id')"
          >
            <input
              class="wf-inp wf-mono"
              [value]="draft().id"
              [readonly]="!isNew()"
              (input)="patch({ id: $any($event.target).value })"
              data-testid="conn-id"
            />
          </wf-field>
          <wf-field class="wf-c-6 wf-c-sm-12" [label]="'sources.name' | wfT" [error]="err('name')">
            <input
              class="wf-inp"
              [value]="draft().name"
              (input)="patch({ name: $any($event.target).value })"
              data-testid="conn-name"
            />
          </wf-field>
          <wf-field
            class="wf-c-12"
            [label]="'sources.connection.baseUrl' | wfT"
            [hint]="'sources.connection.baseUrl.hint' | wfT"
            [error]="err('baseUrl')"
          >
            <input
              class="wf-inp wf-mono"
              inputmode="url"
              [value]="draft().baseUrl"
              (input)="patch({ baseUrl: $any($event.target).value })"
              data-testid="conn-url"
            />
          </wf-field>
          <wf-field class="wf-c-6 wf-c-sm-12" [label]="'sources.connection.auth' | wfT">
            <select
              class="wf-inp"
              (change)="setAuth($any($event.target).value)"
              data-testid="conn-auth"
            >
              @for (a of authTypes; track a) {
                <option [value]="a" [selected]="a === draft().authType">
                  {{ 'sources.connection.auth.' + a | wfT }}
                </option>
              }
            </select>
          </wf-field>
          @if (draft().authType === 'api_key') {
            <wf-field
              class="wf-c-6 wf-c-sm-12"
              [label]="'sources.connection.headerName' | wfT"
              [error]="err('authHeader')"
            >
              <input
                class="wf-inp wf-mono"
                [value]="draft().authHeader"
                placeholder="X-Api-Key"
                (input)="patch({ authHeader: $any($event.target).value })"
                data-testid="conn-header"
              />
            </wf-field>
          }
          @if (draft().authType !== 'none') {
            <div class="wf-c-6 wf-c-sm-12 wf-ds__secret">
              <span class="wf-field-label">{{
                'sources.connection.secret.' + draft().authType | wfT
              }}</span>
              @if (!isNew() && !replacing()) {
                <div class="wf-ds__row">
                  <wf-pill
                    [kind]="stored() ? 'ok' : 'plain'"
                    [dashed]="!stored()"
                    [dot]="true"
                    data-testid="conn-stored"
                  >
                    {{
                      (stored() ? 'sources.connection.stored' : 'sources.connection.notStored')
                        | wfT
                    }}
                  </wf-pill>
                  <button
                    type="button"
                    wfButton
                    (click)="replacing.set(true)"
                    data-testid="conn-replace"
                  >
                    {{ 'sources.connection.replace' | wfT }}
                  </button>
                </div>
              } @else {
                <div class="wf-ds__row wf-ds__row--wrap">
                  @if (draft().authType === 'basic') {
                    <input
                      class="wf-inp"
                      type="text"
                      autocomplete="off"
                      [attr.aria-label]="'sources.connection.user' | wfT"
                      [placeholder]="'sources.connection.user' | wfT"
                      [value]="draft().user"
                      (input)="patch({ user: $any($event.target).value })"
                      data-testid="conn-user"
                    />
                    <input
                      class="wf-inp"
                      type="password"
                      autocomplete="new-password"
                      [attr.aria-label]="'sources.connection.password' | wfT"
                      [placeholder]="'sources.connection.password' | wfT"
                      [value]="draft().password"
                      (input)="patch({ password: $any($event.target).value })"
                      data-testid="conn-password"
                    />
                  } @else {
                    <input
                      class="wf-inp wf-mono"
                      type="password"
                      autocomplete="new-password"
                      [attr.aria-label]="'sources.connection.secret.' + draft().authType | wfT"
                      [value]="draft().secret"
                      (input)="patch({ secret: $any($event.target).value })"
                      data-testid="conn-secret"
                    />
                  }
                  @if (!isNew()) {
                    <button
                      type="button"
                      wfButton
                      variant="primary"
                      size="sm"
                      [busy]="busy()"
                      (click)="replaceSecret()"
                      data-testid="conn-replace-apply"
                    >
                      {{ 'sources.connection.replaceApply' | wfT }}
                    </button>
                    <button
                      type="button"
                      wfButton
                      variant="text"
                      size="sm"
                      (click)="cancelReplace()"
                    >
                      {{ 'action.cancel' | wfT }}
                    </button>
                  }
                </div>
                @if (err('secret'); as e) {
                  <span class="wf-ds__error" role="alert">{{ e }}</span>
                }
              }
              <p class="wf-hint">{{ 'sources.connection.secretHint' | wfT }}</p>
            </div>
          }
        </div>

        @if (!isNew()) {
          <div class="wf-ds__row wf-ds__row--wrap">
            <button
              type="button"
              wfButton
              [busy]="testing()"
              (click)="test()"
              data-testid="conn-test"
            >
              {{ 'sources.connection.test' | wfT }}
            </button>
            @if (testResult(); as r) {
              <wf-pill [kind]="r.ok ? 'ok' : 'error'" [mono]="true" data-testid="conn-test-result">
                {{
                  r.status === null
                    ? ('sources.connection.testFailed' | wfT: { ms: r.durationMs })
                    : ('sources.connection.testResult'
                      | wfT: { status: r.status, ms: r.durationMs })
                }}
              </wf-pill>
              @if (!r.ok && r.message) {
                <span class="wf-hint">{{ r.message }}</span>
              }
            }
            <span class="wf-hint">{{ 'sources.connection.testHint' | wfT }}</span>
          </div>
        }

        <div class="wf-ds__foot">
          <span class="wf-ds__usage" data-testid="ds-usage">
            @if (isNew()) {
            } @else if (usage().length > 0) {
              <strong>{{ 'sources.connection.usage' | wfT: { n: usage().length } }}</strong>
              @for (m of usage(); track m; let last = $last) {
                <a class="wf-mono" href="" (click)="$event.preventDefault(); goTo('modelle', m)">{{
                  m
                }}</a
                >{{ last ? '' : ', ' }}
              }
            } @else {
              <span class="wf-hint">{{ 'sources.connection.usage.none' | wfT }}</span>
            }
          </span>
          <span class="wf-ed-spacer"></span>
          @if (!isNew()) {
            @if (usage().length > 0) {
              <span class="wf-hint">{{ 'sources.deleteLocked' | wfT }}</span>
            }
            <button
              type="button"
              wfButton
              variant="danger"
              [disabled]="locked()"
              (click)="remove()"
              data-testid="ds-delete"
            >
              {{ 'action.delete' | wfT }}
            </button>
          }
          <button
            type="button"
            wfButton
            variant="primary"
            [busy]="busy()"
            (click)="save()"
            data-testid="ds-save"
          >
            {{ 'action.save' | wfT }}
          </button>
        </div>
        @if (!isNew()) {
          <wf-ds-audit type="connection" [objectId]="draft().id" [reload]="auditReload()" />
        }
      }
    </section>
  `,
})
export class WfConnectionEditor extends WfSourceEditorBase<ConnectionDraft> {
  readonly area = 'verbindungen' as const;
  protected readonly authTypes = AUTH_TYPES;
  private readonly connections = signal<readonly Connection[]>([]);
  protected readonly items = computed<SourceListItem[]>(() =>
    this.connections().map((c) => ({ id: c.id, title: c.name, sub: c.id })),
  );
  protected readonly stored = signal(false);
  protected readonly replacing = signal(false);
  protected readonly testing = signal(false);
  protected readonly testResult = signal<ConnectionTest | null>(null);
  protected readonly usage = signal<readonly string[]>([]);
  protected readonly locked = computed(() => this.usage().length > 0);

  constructor() {
    super();
    void this.loadList();
  }

  protected empty(): ConnectionDraft {
    return connectionDraft(null);
  }

  protected idOf(d: ConnectionDraft): string {
    return d.id;
  }

  /** Eingegebene Zugangsdaten zählen nur bei einer neuen Verbindung als Änderung. */
  protected override comparable(d: ConnectionDraft): string {
    return JSON.stringify(this.isNew() ? d : { ...d, secret: '', user: '', password: '' });
  }

  private async loadList(): Promise<void> {
    try {
      this.connections.set((await firstValueFrom(this.api.dataSources.connections())).connections);
    } catch (error) {
      this.showError(error);
    }
  }

  protected async fetch(id: string): Promise<void> {
    const c = await firstValueFrom(this.api.dataSources.connection(id));
    this.stored.set(c.secretStored);
    this.replacing.set(false);
    this.testResult.set(null);
    this.markSaved(connectionDraft(c));
    this.usage.set([]);
    try {
      this.usage.set((await firstValueFrom(this.api.dataSources.connectionUsage(id))).models);
    } catch {
      // Ohne Verwendung bleibt Löschen offen; die Engine prüft ohnehin.
    }
  }

  protected setAuth(authType: AuthType): void {
    this.patch({ authType, secret: '', user: '', password: '' });
  }

  protected cancelReplace(): void {
    this.replacing.set(false);
    this.patch({ secret: '', user: '', password: '' });
  }

  /** «Ersetzen → Übernehmen»: sofort speichern, danach ist das Feld wieder leer (F-135). */
  protected async replaceSecret(): Promise<void> {
    const secret = enteredSecret(this.draft());
    if (secret === '') {
      this.errors.set({ secret: this.i18n.t('sources.connection.secretMissing') });
      return;
    }
    this.busy.set(true);
    try {
      const c = await firstValueFrom(this.api.dataSources.replaceSecret(this.draft().id, secret));
      this.stored.set(c.secretStored);
      this.cancelReplace();
      this.errors.set({});
      this.message.set({ tone: 'ok', text: this.i18n.t('sources.connection.replaced') });
      this.auditReload.update((n) => n + 1);
    } catch (error) {
      this.showError(error);
    } finally {
      this.busy.set(false);
    }
  }

  protected async test(): Promise<void> {
    this.testing.set(true);
    try {
      this.testResult.set(
        await firstValueFrom(this.api.dataSources.testConnection(this.draft().id)),
      );
    } catch (error) {
      this.showError(error);
    } finally {
      this.testing.set(false);
    }
  }

  async save(): Promise<boolean> {
    const d = this.draft();
    if (!this.check(validateConnection(d))) {
      return false;
    }
    this.busy.set(true);
    try {
      const input = toConnectionInput(d);
      const wasNew = this.isNew();
      const saved = wasNew
        ? await firstValueFrom(this.api.dataSources.createConnection(input))
        : await firstValueFrom(this.api.dataSources.updateConnection(d.id, withoutSecret(input)));
      this.stored.set(saved.secretStored);
      this.isNew.set(false);
      this.replacing.set(false);
      this.markSaved(connectionDraft(saved));
      this.errors.set({});
      await this.loadList();
      this.message.set({ tone: 'ok', text: this.i18n.t('sources.saved', { id: saved.id }) });
      this.auditReload.update((n) => n + 1);
      if (wasNew) {
        this.navigate(saved.id);
      }
      return true;
    } catch (error) {
      this.showError(error, (f) => f, ['connection']);
      return false;
    } finally {
      this.busy.set(false);
    }
  }

  protected async remove(): Promise<void> {
    const id = this.draft().id;
    if (!(await this.confirmDelete(id))) {
      return;
    }
    try {
      await firstValueFrom(this.api.dataSources.deleteConnection(id));
      this.editing.set(false);
      await this.loadList();
      this.navigate(null);
      this.message.set({ tone: 'ok', text: this.i18n.t('sources.deleted', { id }) });
    } catch (error) {
      this.showError(error, (f) => f, ['connection']);
    }
  }
}
