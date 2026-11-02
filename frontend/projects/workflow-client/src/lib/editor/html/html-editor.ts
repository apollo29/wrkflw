import {
  afterNextRender,
  ChangeDetectionStrategy,
  Component,
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
import StarterKit from '@tiptap/starter-kit';
import { WfTranslatePipe } from '../../i18n/i18n';
import { WfButton } from '../../ui/button';
import { WfIcon } from '../../ui/icon';
import { EmailButton, PlaceholderHighlight } from './email-extensions';

type Bar = 'link' | 'button' | null;

/** Nur http(s), mailto und Platzhalter als Ziel; alles andere wird nicht übernommen. */
export function safeHref(raw: string): string | null {
  const href = raw.trim();
  if (href === '') {
    return null;
  }
  return /^(https?:\/\/|mailto:|\{\{[\w.]+\}\})/i.test(href) ? href : null;
}

/**
 * HTML-Editor für E-Mail-Inhalte (TipTap 3): Formatierung, Listen, Link, Knopf und
 * Platzhalter. Adressen werden in einer Leiste im Editor eingegeben, nicht in
 * Browser-Dialogen (9.14). Tabellen, Bilder und Vorschau folgen mit den Vorlagen.
 */
@Component({
  selector: 'wf-html-editor',
  imports: [WfButton, WfIcon, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="wf-html" [class.wf-html--disabled]="disabled()">
      <div class="wf-html__toolbar" role="toolbar" [attr.aria-label]="'html.toolbar' | wfT">
        <button
          type="button"
          wfButton
          variant="icon"
          size="sm"
          [attr.aria-label]="'html.bold' | wfT"
          [attr.aria-pressed]="isActive('bold')"
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
          [attr.aria-pressed]="isActive('italic')"
          (click)="run('italic')"
        >
          <i aria-hidden="true">I</i>
        </button>
        <button
          type="button"
          wfButton
          variant="icon"
          size="sm"
          [attr.aria-label]="'html.heading' | wfT"
          [attr.aria-pressed]="isActive('heading')"
          (click)="run('heading')"
        >
          <span aria-hidden="true">H</span>
        </button>
        <button
          type="button"
          wfButton
          variant="icon"
          size="sm"
          [attr.aria-label]="'html.bulletList' | wfT"
          [attr.aria-pressed]="isActive('bulletList')"
          (click)="run('bulletList')"
        >
          <span aria-hidden="true">•</span>
        </button>
        <button
          type="button"
          wfButton
          variant="icon"
          size="sm"
          [attr.aria-label]="'html.orderedList' | wfT"
          [attr.aria-pressed]="isActive('orderedList')"
          (click)="run('orderedList')"
        >
          <span aria-hidden="true">1.</span>
        </button>
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
          variant="text"
          size="sm"
          [attr.aria-expanded]="bar() === 'button'"
          (click)="openBar('button')"
        >
          {{ 'html.button' | wfT }}
        </button>
        @if (placeholders().length > 0) {
          <select
            class="wf-inp wf-html__ph"
            [attr.aria-label]="'html.placeholder.insert' | wfT"
            (change)="insertPlaceholder($any($event.target))"
          >
            <option value="">{{ 'html.placeholder' | wfT }}</option>
            @for (p of placeholders(); track p) {
              <option [value]="p">{{ token(p) }}</option>
            }
          </select>
        }
        <span class="wf-html__spacer"></span>
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
          @if (kind === 'link' && isActive('link')) {
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
        </form>
      }
      <div #host class="wf-html__content" [attr.aria-label]="label()"></div>
    </div>
  `,
  styles: `
    .wf-html {
      border: 1px solid var(--wfb-border-strong);
      border-radius: var(--wfb-radius-sm);
      background: var(--wfb-bg);
      overflow: hidden;
    }
    .wf-html--disabled {
      opacity: 0.7;
      pointer-events: none;
    }
    .wf-html__toolbar,
    .wf-html__bar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 4px;
      padding: 6px 8px;
      border-bottom: 1px solid var(--wfb-border);
      background: var(--wfb-bg-soft);
    }
    .wf-html__bar {
      align-items: flex-end;
      gap: 8px;
    }
    .wf-html__barfield {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-width: 140px;
    }
    .wf-html__barfield--grow {
      flex: 1;
    }
    .wf-html__spacer {
      flex: 1;
    }
    .wf-html__ph {
      width: auto;
      height: var(--wfb-control-h-sm);
      font-size: var(--wfb-text-sm);
    }
    .wf-html__toolbar [aria-pressed='true'] {
      background: var(--wfb-primary-soft);
      color: var(--wfb-primary-fg);
    }
    .wf-html__content {
      padding: 10px 12px;
      min-height: 140px;
    }
    .wf-html__content :where(.ProseMirror) {
      outline: none;
      min-height: 120px;
    }
    .wf-html__content :where(.ProseMirror p) {
      margin: 0 0 8px;
    }
    .wf-html__content :where(.wf-ph) {
      padding: 0 4px;
      border-radius: 4px;
      background: var(--wfb-primary-soft);
      color: var(--wfb-primary-fg);
      font-family: var(--wfb-font-mono);
      font-size: 0.92em;
    }
  `,
})
export class WfHtmlEditor {
  readonly value = model('');
  readonly placeholders = input<readonly string[]>([]);
  readonly label = input('');
  readonly disabled = input(false);

  protected readonly bar = signal<Bar>(null);
  protected readonly barText = signal('');
  protected readonly barUrl = signal('');
  private readonly tick = signal(0);

  private readonly host = viewChild.required<ElementRef<HTMLDivElement>>('host');
  private editor: Editor | null = null;
  private applying = false;

  constructor() {
    afterNextRender(() => {
      this.editor = new Editor({
        element: this.host().nativeElement,
        editable: !this.disabled(),
        extensions: [
          StarterKit.configure({ link: { openOnClick: false, autolink: true } }),
          PlaceholderHighlight,
          EmailButton,
        ],
        content: this.value() || '<p></p>',
        onUpdate: ({ editor }) => {
          if (!this.applying) {
            this.value.set(editor.getHTML());
          }
          this.tick.update((n) => n + 1);
        },
        onSelectionUpdate: () => this.tick.update((n) => n + 1),
      });
    });
    effect(() => {
      const value = this.value();
      if (this.editor && this.editor.getHTML() !== value) {
        this.applying = true;
        this.editor.commands.setContent(value || '<p></p>', { emitUpdate: false });
        this.applying = false;
      }
    });
    effect(() => this.editor?.setEditable(!this.disabled()));
    inject(DestroyRef).onDestroy(() => this.editor?.destroy());
  }

  protected isActive(name: string): boolean {
    this.tick();
    return this.editor?.isActive(name) ?? false;
  }

  protected run(
    command: 'bold' | 'italic' | 'heading' | 'bulletList' | 'orderedList' | 'undo' | 'redo',
  ): void {
    const chain = this.editor?.chain().focus();
    if (!chain) {
      return;
    }
    switch (command) {
      case 'bold':
        chain.toggleBold().run();
        break;
      case 'italic':
        chain.toggleItalic().run();
        break;
      case 'heading':
        chain.toggleHeading({ level: 2 }).run();
        break;
      case 'bulletList':
        chain.toggleBulletList().run();
        break;
      case 'orderedList':
        chain.toggleOrderedList().run();
        break;
      case 'undo':
        chain.undo().run();
        break;
      case 'redo':
        chain.redo().run();
        break;
    }
  }

  protected openBar(kind: 'link' | 'button'): void {
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
    this.bar.set(kind);
  }

  /** Link setzen oder Knopf einfügen, ohne Browser-Dialog. */
  applyBar(): void {
    const href = safeHref(this.barUrl());
    const chain = this.editor?.chain().focus();
    if (!chain || href === null) {
      return;
    }
    if (this.bar() === 'link') {
      chain.extendMarkRange('link').setLink({ href }).run();
    } else {
      const label = this.barText().trim();
      if (label === '') {
        return;
      }
      chain.insertContent({ type: 'emailButton', attrs: { label, href } }).run();
    }
    this.bar.set(null);
  }

  protected unlink(): void {
    this.editor?.chain().focus().extendMarkRange('link').unsetLink().run();
    this.bar.set(null);
  }

  protected insertPlaceholder(select: HTMLSelectElement): void {
    const name = select.value;
    select.value = '';
    if (name !== '') {
      this.editor?.chain().focus().insertContent(`{{${name}}}`).run();
    }
  }

  protected token(name: string): string {
    return `{{${name}}}`;
  }

  /** Für Tests: Inhalt an der Auswahl setzen. */
  selectAll(): void {
    this.editor?.commands.selectAll();
  }
}
