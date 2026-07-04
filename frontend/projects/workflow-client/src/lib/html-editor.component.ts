import {
  AfterViewInit,
  Component,
  effect,
  ElementRef,
  inject,
  input,
  model,
  OnDestroy,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { Editor } from '@tiptap/core';
import TextAlign from '@tiptap/extension-text-align';
import StarterKit from '@tiptap/starter-kit';

/**
 * Reicher WYSIWYG-/Template-Editor für HTML-Inhalte (z. B. E-Mail-Bodies) auf Basis
 * von TipTap. Bietet Formatierung (fett/kursiv/unterstrichen/durchgestrichen,
 * Überschriften, Listen, Zitat, Ausrichtung, Link, Undo/Redo), einen HTML-Quelltext-
 * Umschalter, das Einfügen von Platzhaltern (`{{feld}}`) sowie eine Live-Vorschau mit
 * Beispielwerten.
 *
 * Zweiweg-Bindung über `[(value)]`; verfügbare Platzhalter über `[placeholders]`;
 * optionale Beispielwerte für die Vorschau über `[sampleContext]`.
 */
@Component({
  selector: 'wf-html-editor',
  standalone: true,
  imports: [FormsModule],
  templateUrl: './html-editor.component.html',
  styleUrl: './html-editor.component.css',
})
export class HtmlEditorComponent implements AfterViewInit, OnDestroy {
  readonly value = model<string>('');
  readonly placeholders = input<string[]>([]);
  readonly sampleContext = input<Record<string, string>>({});

  readonly showSource = signal(false);
  readonly showPreview = signal(false);
  readonly custom = signal('');
  readonly pick = signal('');
  readonly refresh = signal(0);

  private readonly host = viewChild<ElementRef<HTMLDivElement>>('host');
  private readonly sanitizer = inject(DomSanitizer);
  private editor: Editor | null = null;
  private editing = false;
  private settingContent = false;

  constructor() {
    // Externe Wertänderungen (z. B. Laden einer Definition) in den Editor spiegeln.
    effect(() => {
      const v = this.value();
      if (this.editor && !this.editing && !this.showSource() && this.editor.getHTML() !== v) {
        this.settingContent = true;
        this.editor.commands.setContent(v || '<p></p>');
        this.settingContent = false;
      }
    });
  }

  ngAfterViewInit(): void {
    const element = this.host()?.nativeElement;
    if (!element) {
      return;
    }
    this.editor = new Editor({
      element,
      extensions: [
        StarterKit.configure({ link: { openOnClick: false } }),
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
      ],
      content: this.value() || '<p></p>',
      onUpdate: ({ editor }) => {
        if (this.settingContent) {
          return;
        }
        this.editing = true;
        this.value.set(editor.getHTML());
        this.refresh.update((n) => n + 1);
      },
      onSelectionUpdate: () => this.refresh.update((n) => n + 1),
      onBlur: () => {
        this.editing = false;
      },
    });
  }

  ngOnDestroy(): void {
    this.editor?.destroy();
  }

  // -- Toolbar ------------------------------------------------------------

  toggle(mark: 'Bold' | 'Italic' | 'Underline' | 'Strike'): void {
    switch (mark) {
      case 'Bold':
        this.editor?.chain().focus().toggleBold().run();
        break;
      case 'Italic':
        this.editor?.chain().focus().toggleItalic().run();
        break;
      case 'Underline':
        this.editor?.chain().focus().toggleUnderline().run();
        break;
      case 'Strike':
        this.editor?.chain().focus().toggleStrike().run();
        break;
    }
  }

  heading(level: 2 | 3): void {
    this.editor?.chain().focus().toggleHeading({ level }).run();
  }

  bulletList(): void {
    this.editor?.chain().focus().toggleBulletList().run();
  }

  orderedList(): void {
    this.editor?.chain().focus().toggleOrderedList().run();
  }

  blockquote(): void {
    this.editor?.chain().focus().toggleBlockquote().run();
  }

  align(dir: 'left' | 'center' | 'right'): void {
    this.editor?.chain().focus().setTextAlign(dir).run();
  }

  undo(): void {
    this.editor?.chain().focus().undo().run();
  }

  redo(): void {
    this.editor?.chain().focus().redo().run();
  }

  clearFormat(): void {
    this.editor?.chain().focus().unsetAllMarks().clearNodes().run();
  }

  setLink(): void {
    const url = window.prompt('Link-Adresse (URL) – leer = entfernen');
    if (url === null) {
      return;
    }
    if (url === '') {
      this.editor?.chain().focus().extendMarkRange('link').unsetLink().run();
    } else {
      this.editor?.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
    }
  }

  isActive(name: string, attrs?: Record<string, unknown>): boolean {
    this.refresh();
    return this.editor?.isActive(name, attrs) ?? false;
  }

  // -- Platzhalter --------------------------------------------------------

  onPick(name: string): void {
    if (name) {
      this.insertPlaceholder(name);
    }
    this.pick.set('');
  }

  addCustom(): void {
    const name = this.custom().trim();
    if (name) {
      this.insertPlaceholder(name);
      this.custom.set('');
    }
  }

  insertPlaceholder(name: string): void {
    const token = `{{${name.trim()}}}`;
    if (name.trim() === '') {
      return;
    }
    if (this.showSource() || !this.editor) {
      this.value.set((this.value() ?? '') + token);
      return;
    }
    this.editor.chain().focus().insertContent(token).run();
  }

  // -- Umschalter & Vorschau ----------------------------------------------

  toggleSource(): void {
    this.showSource.update((v) => !v);
  }

  togglePreview(): void {
    this.showPreview.update((v) => !v);
  }

  previewHtml(): SafeHtml {
    return this.sanitizer.bypassSecurityTrustHtml(this.resolvePlaceholders(this.value()));
  }

  private resolvePlaceholders(html: string): string {
    return html.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_match, key: string) => {
      return this.sampleContext()[key] ?? this.sampleFor(key);
    });
  }

  private sampleFor(key: string): string {
    const k = key.toLowerCase();
    if (k.includes('email') || k.includes('mail')) {
      return 'max@example.com';
    }
    if (k.includes('vorname')) {
      return 'Max';
    }
    if (k.includes('name')) {
      return 'Max Mustermann';
    }
    if (k.includes('betrag') || k.includes('amount') || k.includes('preis') || k.includes('price')) {
      return '199,00 €';
    }
    if (k.includes('datum') || k.includes('date')) {
      return '01.01.2026';
    }
    return `[${key}]`;
  }
}
