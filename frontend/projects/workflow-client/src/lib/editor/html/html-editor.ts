import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  effect,
  ElementRef,
  inject,
  input,
  model,
  signal,
  viewChild,
} from '@angular/core';
import { Editor } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import StarterKit from '@tiptap/starter-kit';
import { WF_CONFIG } from '../../config/config';
import { WfI18n, WfTranslatePipe } from '../../i18n/i18n';
import { WfButton } from '../../ui/button';
import { WfIcon } from '../../ui/icon';
import {
  Column,
  Columns,
  EmailButton,
  emailifyHtml,
  PlaceholderHighlight,
  previewDocument,
  previewHtml,
} from './email-extensions';

type Bar = 'link' | 'button' | 'image' | null;
type Command =
  | 'bold'
  | 'italic'
  | 'underline'
  | 'strike'
  | 'h2'
  | 'h3'
  | 'bulletList'
  | 'orderedList'
  | 'blockquote'
  | 'left'
  | 'center'
  | 'right'
  | 'clear'
  | 'undo'
  | 'redo'
  | 'rule'
  | 'columns'
  | 'table'
  | 'rowAdd'
  | 'rowDelete'
  | 'colAdd'
  | 'colDelete'
  | 'headerRow'
  | 'tableDelete';

/** Nur http(s), mailto und Platzhalter als Ziel; alles andere wird nicht übernommen. */
export function safeHref(raw: string): string | null {
  const href = raw.trim();
  if (href === '') {
    return null;
  }
  return /^(https?:\/\/|mailto:|\{\{[\w.]+\}\})/i.test(href) ? href : null;
}

/** Bilder nur über `https:` (oder Platzhalter); keine Daten-URLs in E-Mails. */
export function safeImageSrc(raw: string): string | null {
  const src = raw.trim();
  return /^(https:\/\/|\/(?!\/)|\{\{[\w.]+\}\})/i.test(src) ? src : null;
}

/** Erlaubte Namen für «eigener Platzhalter». */
export const PLACEHOLDER_NAME = /^[\w.]+$/;

/**
 * HTML-Editor für Vorlagen und E-Mail-Inhalte (9.11, TipTap 3): Werkzeugleiste in Gruppen,
 * Platzhalter, Bild, Button, Trenner, «2 Spalten», E-Mail-taugliche Tabellen, HTML-Quelltext
 * und Vorschau mit Beispielwerten. Adressen werden im Editor eingegeben, nicht in
 * Browser-Dialogen (9.14).
 */
@Component({
  selector: 'wf-html-editor',
  imports: [WfButton, WfIcon, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="wf-html" [class.wf-html--disabled]="disabled()">
      <div class="wf-html__toolbar" role="toolbar" [attr.aria-label]="'html.toolbar' | wfT">
        <span class="wf-html__group">
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.bold' | wfT"
            [attr.aria-pressed]="on('bold')"
            (click)="run('bold')"
          >
            <b aria-hidden="true">B</b>
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.italic' | wfT"
            [attr.aria-pressed]="on('italic')"
            (click)="run('italic')"
          >
            <i aria-hidden="true">I</i>
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.underline' | wfT"
            [attr.aria-pressed]="on('underline')"
            (click)="run('underline')"
          >
            <u aria-hidden="true">U</u>
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.strike' | wfT"
            [attr.aria-pressed]="on('strike')"
            (click)="run('strike')"
          >
            <s aria-hidden="true">S</s>
          </button>
        </span>
        <span class="wf-html__group">
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="on('heading', { level: 2 })"
            (click)="run('h2')"
          >
            H2
          </button>
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="on('heading', { level: 3 })"
            (click)="run('h3')"
          >
            H3
          </button>
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="on('bulletList')"
            (click)="run('bulletList')"
          >
            {{ 'html.bulletList' | wfT }}
          </button>
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="on('orderedList')"
            (click)="run('orderedList')"
          >
            {{ 'html.orderedList' | wfT }}
          </button>
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="on('blockquote')"
            (click)="run('blockquote')"
          >
            {{ 'html.quote' | wfT }}
          </button>
        </span>
        <span class="wf-html__group">
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.alignLeft' | wfT"
            [attr.aria-pressed]="on({ textAlign: 'left' })"
            (click)="run('left')"
          >
            <wf-icon name="align-left" />
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.alignCenter' | wfT"
            [attr.aria-pressed]="on({ textAlign: 'center' })"
            (click)="run('center')"
          >
            <wf-icon name="align-center" />
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.alignRight' | wfT"
            [attr.aria-pressed]="on({ textAlign: 'right' })"
            (click)="run('right')"
          >
            <wf-icon name="align-right" />
          </button>
        </span>
        <span class="wf-html__group">
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-expanded]="bar() === 'link'"
            (click)="openBar('link')"
          >
            {{ 'html.link' | wfT }}
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.undo' | wfT"
            (click)="run('undo')"
          >
            <wf-icon name="undo" />
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.redo' | wfT"
            (click)="run('redo')"
          >
            <wf-icon name="redo" />
          </button>
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'html.clear' | wfT"
            (click)="run('clear')"
          >
            <wf-icon name="clear-format" />
          </button>
        </span>
        <span class="wf-html__group">
          <select
            class="wf-inp wf-html__ph"
            [attr.aria-label]="'html.placeholder' | wfT"
            (change)="pickPlaceholder($any($event.target))"
            data-testid="html-placeholder"
          >
            <option value="">{{ 'html.placeholder.choose' | wfT }}</option>
            @for (p of placeholders(); track p) {
              <option [value]="p">{{ p }}</option>
            }
          </select>
          <input
            class="wf-inp wf-mono wf-html__custom"
            [attr.aria-label]="'html.placeholder.custom' | wfT"
            [placeholder]="'html.placeholder.custom' | wfT"
            [value]="custom()"
            (input)="custom.set($any($event.target).value)"
            (keydown.enter)="$event.preventDefault(); insertCustom()"
            data-testid="html-custom"
          />
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [disabled]="!customValid()"
            (click)="insertCustom()"
          >
            {{ 'html.placeholder.insert' | wfT }}
          </button>
        </span>
        <span class="wf-html__group">
          @if (uploadImage) {
            <button type="button" wfButton variant="text" size="sm" (click)="fileInput.click()">
              {{ 'html.image' | wfT }}
            </button>
            <input
              #fileInput
              type="file"
              accept="image/*"
              hidden
              (change)="uploadImageFile($any($event.target))"
              data-testid="html-image-file"
            />
          }
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-expanded]="bar() === 'image'"
            (click)="openBar('image')"
          >
            {{ 'html.imageUrl' | wfT }}
          </button>
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-expanded]="bar() === 'button'"
            (click)="openBar('button')"
          >
            {{ 'html.button' | wfT }}
          </button>
          <button type="button" wfButton variant="text" size="sm" (click)="run('rule')">
            {{ 'html.rule' | wfT }}
          </button>
          <button type="button" wfButton variant="text" size="sm" (click)="run('columns')">
            {{ 'html.columns' | wfT }}
          </button>
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="on('table')"
            (click)="run('table')"
            data-testid="html-table"
          >
            {{ 'html.table' | wfT }}
          </button>
        </span>
        <span class="wf-html__spacer"></span>
        <span class="wf-html__group">
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="source()"
            (click)="toggleSource()"
            data-testid="html-source-toggle"
          >
            {{ 'html.source' | wfT }}
          </button>
          <button
            type="button"
            wfButton
            variant="text"
            size="sm"
            [attr.aria-pressed]="preview()"
            (click)="preview.set(!preview())"
            data-testid="html-preview-toggle"
          >
            {{ 'html.preview' | wfT }}
          </button>
        </span>
        @if (on('table')) {
          <div
            class="wf-html__tablebar"
            role="group"
            [attr.aria-label]="'html.table' | wfT"
            data-testid="html-tablebar"
          >
            <span class="wf-lbl">{{ 'html.table' | wfT }}</span>
            <button type="button" wfButton variant="text" size="sm" (click)="run('rowAdd')">
              {{ 'html.rowAdd' | wfT }}
            </button>
            <button type="button" wfButton variant="text" size="sm" (click)="run('rowDelete')">
              {{ 'html.rowDelete' | wfT }}
            </button>
            <button type="button" wfButton variant="text" size="sm" (click)="run('colAdd')">
              {{ 'html.colAdd' | wfT }}
            </button>
            <button type="button" wfButton variant="text" size="sm" (click)="run('colDelete')">
              {{ 'html.colDelete' | wfT }}
            </button>
            <button type="button" wfButton variant="text" size="sm" (click)="run('headerRow')">
              {{ 'html.headerRow' | wfT }}
            </button>
            <button type="button" wfButton variant="danger" size="sm" (click)="run('tableDelete')">
              {{ 'html.tableDelete' | wfT }}
            </button>
          </div>
        }
      </div>
      @if (bar(); as kind) {
        <form class="wf-html__bar" (submit)="$event.preventDefault(); applyBar()">
          @if (kind === 'button') {
            <label class="wf-html__barfield">
              <span class="wf-lbl">{{ 'html.text' | wfT }}</span>
              <input
                class="wf-inp"
                [value]="barText()"
                (input)="barText.set($any($event.target).value)"
                data-testid="html-bar-text"
              />
            </label>
          }
          <label class="wf-html__barfield wf-html__barfield--grow">
            <span class="wf-lbl">{{ 'html.url' | wfT }}</span>
            <input
              class="wf-inp wf-mono"
              [value]="barUrl()"
              (input)="barUrl.set($any($event.target).value)"
              placeholder="https://"
              data-testid="html-bar-url"
            />
          </label>
          <button type="submit" wfButton variant="primary" size="sm">
            {{ 'html.apply' | wfT }}
          </button>
          @if (kind === 'link' && on('link')) {
            <button type="button" wfButton size="sm" (click)="unlink()">
              {{ 'html.unlink' | wfT }}
            </button>
          }
          <button
            type="button"
            wfButton
            variant="icon"
            size="sm"
            [attr.aria-label]="'action.close' | wfT"
            (click)="bar.set(null)"
          >
            <wf-icon name="close" />
          </button>
          @if (barError()) {
            <span class="wf-html__barerror" role="alert">{{ 'html.urlInvalid' | wfT }}</span>
          }
        </form>
      }
      <div #host class="wf-html__content" [hidden]="source()" [attr.aria-label]="label()"></div>
      @if (source()) {
        <textarea
          class="wf-inp wf-mono wf-html__source"
          spellcheck="false"
          [attr.aria-label]="'html.source' | wfT"
          [value]="sourceDraft()"
          (input)="sourceDraft.set($any($event.target).value)"
          data-testid="html-source"
        ></textarea>
      }
    </div>
    @if (preview()) {
      <div class="wf-html__preview" data-testid="html-preview">
        <span class="wf-lbl">{{ 'html.previewTitle' | wfT }}</span>
        <!-- Ohne allow-scripts läuft im Rahmen kein Skript; allow-same-origin nur für die Höhe. -->
        <iframe
          #frame
          class="wf-html__frame"
          sandbox="allow-same-origin"
          [title]="'html.previewTitle' | wfT"
          (load)="fitFrame()"
          data-testid="html-preview-frame"
        ></iframe>
      </div>
    }
  `,
})
export class WfHtmlEditor {
  readonly value = model('');
  readonly placeholders = input<readonly string[]>([]);
  /** Beispielwerte für die Vorschau; fehlende erscheinen als «[Beispielwert x]». */
  readonly sampleValues = input<Readonly<Record<string, string>>>({});
  readonly label = input('');
  readonly disabled = input(false);

  private readonly i18n = inject(WfI18n);
  protected readonly uploadImage = inject(WF_CONFIG, { optional: true })?.uploadImage ?? null;

  protected readonly bar = signal<Bar>(null);
  protected readonly barText = signal('');
  protected readonly barUrl = signal('');
  protected readonly barError = signal(false);
  protected readonly custom = signal('');
  protected readonly customValid = computed(() => PLACEHOLDER_NAME.test(this.custom().trim()));
  protected readonly source = signal(false);
  protected readonly sourceDraft = signal('');
  readonly preview = signal(false);
  protected readonly previewBody = computed(() =>
    previewHtml(this.value(), this.sampleValues(), (name) => this.i18n.t('html.sample', { name })),
  );
  private readonly tick = signal(0);

  private readonly host = viewChild.required<ElementRef<HTMLDivElement>>('host');
  private readonly frame = viewChild<ElementRef<HTMLIFrameElement>>('frame');
  private editor: Editor | null = null;
  private applying = false;

  constructor() {
    effect(() => {
      const frame = this.frame()?.nativeElement;
      if (frame) {
        // srcdoc als Attribut: der Inhalt bleibt im Sandbox-Rahmen, kein Sanitizer-Bypass nötig.
        frame.setAttribute('srcdoc', previewDocument(this.previewBody()));
      }
    });
    afterNextRender(() => {
      this.editor = new Editor({
        element: this.host().nativeElement,
        editable: !this.disabled(),
        extensions: [
          StarterKit.configure({ link: { openOnClick: false, autolink: true } }),
          TextAlign.configure({ types: ['heading', 'paragraph'] }),
          Image.configure({
            inline: false,
            allowBase64: false,
            HTMLAttributes: { style: 'max-width:100%;height:auto;' },
          }),
          Table.configure({ resizable: false }),
          TableRow,
          TableHeader,
          TableCell,
          PlaceholderHighlight,
          EmailButton,
          Columns,
          Column,
        ],
        content: this.value() || '<p></p>',
        onUpdate: ({ editor }) => {
          if (!this.applying) {
            this.value.set(emailifyHtml(editor.getHTML()));
          }
          this.tick.update((n) => n + 1);
        },
        onSelectionUpdate: () => this.tick.update((n) => n + 1),
      });
    });
    effect(() => {
      const value = this.value();
      const editor = this.editor;
      if (editor && emailifyHtml(editor.getHTML()) !== value) {
        this.applying = true;
        editor.commands.setContent(value || '<p></p>', { emitUpdate: false });
        this.applying = false;
        this.tick.update((n) => n + 1);
      }
    });
    effect(() => this.editor?.setEditable(!this.disabled()));
    inject(DestroyRef).onDestroy(() => this.editor?.destroy());
  }

  protected on(
    nameOrAttrs: string | Record<string, unknown>,
    attrs?: Record<string, unknown>,
  ): boolean {
    this.tick();
    if (!this.editor) {
      return false;
    }
    return typeof nameOrAttrs === 'string'
      ? this.editor.isActive(nameOrAttrs, attrs)
      : this.editor.isActive(nameOrAttrs);
  }

  run(command: Command): void {
    const c = this.editor?.chain().focus();
    if (!c) {
      return;
    }
    switch (command) {
      case 'bold':
        c.toggleBold().run();
        break;
      case 'italic':
        c.toggleItalic().run();
        break;
      case 'underline':
        c.toggleUnderline().run();
        break;
      case 'strike':
        c.toggleStrike().run();
        break;
      case 'h2':
        c.toggleHeading({ level: 2 }).run();
        break;
      case 'h3':
        c.toggleHeading({ level: 3 }).run();
        break;
      case 'bulletList':
        c.toggleBulletList().run();
        break;
      case 'orderedList':
        c.toggleOrderedList().run();
        break;
      case 'blockquote':
        c.toggleBlockquote().run();
        break;
      case 'left':
      case 'center':
      case 'right':
        c.setTextAlign(command).run();
        break;
      case 'clear':
        c.unsetAllMarks().clearNodes().run();
        break;
      case 'undo':
        c.undo().run();
        break;
      case 'redo':
        c.redo().run();
        break;
      case 'rule':
        c.setHorizontalRule().run();
        break;
      case 'columns':
        c.insertContent({
          type: 'columns',
          content: [
            { type: 'column', content: [{ type: 'paragraph' }] },
            { type: 'column', content: [{ type: 'paragraph' }] },
          ],
        }).run();
        break;
      case 'table':
        c.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
        break;
      case 'rowAdd':
        c.addRowAfter().run();
        break;
      case 'rowDelete':
        c.deleteRow().run();
        break;
      case 'colAdd':
        c.addColumnAfter().run();
        break;
      case 'colDelete':
        c.deleteColumn().run();
        break;
      case 'headerRow':
        c.toggleHeaderRow().run();
        break;
      case 'tableDelete':
        c.deleteTable().run();
        break;
    }
  }

  protected openBar(kind: 'link' | 'button' | 'image'): void {
    if (this.bar() === kind) {
      this.bar.set(null);
      return;
    }
    const href =
      kind === 'link'
        ? ((this.editor?.getAttributes('link')['href'] as string | undefined) ?? '')
        : '';
    this.barUrl.set(href);
    this.barText.set('');
    this.barError.set(false);
    this.bar.set(kind);
  }

  /** Link setzen, Button oder Bild einfügen, ohne Browser-Dialog (9.14). */
  applyBar(): void {
    const kind = this.bar();
    const url = kind === 'image' ? safeImageSrc(this.barUrl()) : safeHref(this.barUrl());
    const c = this.editor?.chain().focus();
    if (!c || url === null) {
      this.barError.set(true);
      return;
    }
    if (kind === 'link') {
      c.extendMarkRange('link').setLink({ href: url }).run();
    } else if (kind === 'image') {
      c.setImage({ src: url }).run();
    } else {
      const label = this.barText().trim();
      if (label === '') {
        this.barError.set(true);
        return;
      }
      c.insertContent({ type: 'emailButton', attrs: { label, href: url } }).run();
    }
    this.bar.set(null);
  }

  protected unlink(): void {
    this.editor?.chain().focus().extendMarkRange('link').unsetLink().run();
    this.bar.set(null);
  }

  protected pickPlaceholder(select: HTMLSelectElement): void {
    const name = select.value;
    select.value = '';
    if (name !== '') {
      this.insertPlaceholder(name);
    }
  }

  protected insertCustom(): void {
    const name = this.custom().trim();
    if (PLACEHOLDER_NAME.test(name)) {
      this.insertPlaceholder(name);
      this.custom.set('');
    }
  }

  insertPlaceholder(name: string): void {
    this.editor?.chain().focus().insertContent(`{{${name}}}`).run();
  }

  protected async uploadImageFile(input: HTMLInputElement): Promise<void> {
    const file = input.files?.[0];
    input.value = '';
    if (!file || !this.uploadImage) {
      return;
    }
    const src = safeImageSrc(await this.uploadImage(file));
    if (src) {
      this.editor?.chain().focus().setImage({ src }).run();
    }
  }

  /** HTML-Quelltext; beim Zurückschalten werden die Änderungen übernommen. */
  protected fitFrame(): void {
    const frame = this.frame()?.nativeElement;
    const doc = frame?.contentDocument;
    if (frame && doc?.body) {
      frame.style.height = `${doc.documentElement.scrollHeight}px`;
    }
  }

  toggleSource(): void {
    if (!this.source()) {
      this.sourceDraft.set(this.value());
      this.source.set(true);
      return;
    }
    this.source.set(false);
    const html = this.sourceDraft();
    this.editor?.commands.setContent(html || '<p></p>', { emitUpdate: false });
    this.value.set(emailifyHtml(this.editor?.getHTML() ?? html));
  }

  /** Für Tests: Cursor an den Anfang des Inhalts setzen. */
  focusStart(): void {
    this.editor?.commands.focus('start');
  }
}
