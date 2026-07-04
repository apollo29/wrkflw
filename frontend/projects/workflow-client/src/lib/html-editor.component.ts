import {
  Component,
  effect,
  ElementRef,
  input,
  model,
  signal,
  viewChild,
} from '@angular/core';
import { FormsModule } from '@angular/forms';

/**
 * Schlanker, abhängigkeitsfreier WYSIWYG-/Template-Editor für HTML-Inhalte
 * (z. B. E-Mail-Bodies). Bietet einfache Formatierung, einen HTML-Quelltext-
 * Umschalter und das Einfügen von Platzhaltern (`{{feld}}`), die die Engine beim
 * Ausführen aus dem Workflow-Kontext ersetzt.
 *
 * Zweiweg-Bindung über `[(value)]`; verfügbare Platzhalter über `[placeholders]`.
 */
@Component({
  selector: 'wf-html-editor',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="wfb-html">
      <div class="wfb-html__toolbar">
        <button type="button" (click)="exec('bold')" title="Fett" aria-label="Fett"><b>B</b></button>
        <button type="button" (click)="exec('italic')" title="Kursiv" aria-label="Kursiv"><i>I</i></button>
        <button type="button" (click)="exec('underline')" title="Unterstrichen" aria-label="Unterstrichen"><u>U</u></button>
        <button type="button" (click)="exec('formatBlock', 'H2')" title="Überschrift">H2</button>
        <button type="button" (click)="exec('insertUnorderedList')" title="Aufzählung">Liste</button>
        <button type="button" (click)="createLink()" title="Link">Link</button>
        <span class="wfb-html__sep"></span>
        <select
          [ngModel]="pick()"
          (ngModelChange)="onPick($event)"
          aria-label="Platzhalter einfügen"
        >
          <option value="">Platzhalter…</option>
          @for (p of placeholders(); track p) {
            <option [value]="p">{{ '{{' + p + '}}' }}</option>
          }
        </select>
        <input
          type="text"
          class="wfb-html__custom"
          placeholder="eigener Platzhalter"
          [ngModel]="custom()"
          (ngModelChange)="custom.set($event)"
          (keydown.enter)="addCustom(); $event.preventDefault()"
          aria-label="Eigener Platzhalter"
        />
        <button type="button" (click)="addCustom()">einfügen</button>
        <span class="wfb-html__sep"></span>
        <button type="button" (click)="toggleSource()">{{ showSource() ? 'Editor' : 'HTML' }}</button>
      </div>

      @if (showSource()) {
        <textarea
          class="wfb-html__source"
          rows="10"
          [ngModel]="value()"
          (ngModelChange)="value.set($event)"
          aria-label="HTML-Quelltext"
        ></textarea>
      } @else {
        <div
          #editor
          class="wfb-html__area"
          contenteditable="true"
          role="textbox"
          aria-multiline="true"
          aria-label="Inhalt"
          (input)="onInput()"
          (blur)="onBlur()"
        ></div>
      }
    </div>
  `,
  styles: [
    `
      .wfb-html { border: 1px solid var(--wfb-border, #d5d7db); border-radius: var(--wfb-radius, 8px); overflow: hidden; }
      .wfb-html__toolbar { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; padding: 6px; background: var(--wfb-bg-soft, #f6f7f9); border-bottom: 1px solid var(--wfb-border, #d5d7db); }
      .wfb-html__toolbar button { padding: 4px 8px; font-size: 13px; line-height: 1; border: 1px solid var(--wfb-border, #d5d7db); background: var(--wfb-bg, #fff); border-radius: 6px; cursor: pointer; }
      .wfb-html__toolbar button:hover { background: var(--wfb-bg-soft, #eef0f3); }
      .wfb-html__custom { width: 130px; }
      .wfb-html__sep { width: 1px; align-self: stretch; background: var(--wfb-border, #d5d7db); margin: 0 2px; }
      .wfb-html__area { min-height: 140px; padding: 10px 12px; outline: none; background: var(--wfb-bg, #fff); color: var(--wfb-text, #1f2329); }
      .wfb-html__area:focus { box-shadow: inset 0 0 0 2px var(--wfb-primary, #2f6feb); }
      .wfb-html__source { width: 100%; border: 0; padding: 10px 12px; font-family: monospace; font-size: 13px; resize: vertical; }
    `,
  ],
})
export class HtmlEditorComponent {
  readonly value = model<string>('');
  readonly placeholders = input<string[]>([]);

  readonly showSource = signal(false);
  readonly custom = signal('');
  readonly pick = signal('');

  private readonly editorRef = viewChild<ElementRef<HTMLDivElement>>('editor');
  private editing = false;

  constructor() {
    // Externe Wertänderungen (z. B. Laden einer Definition) in den contentEditable
    // spiegeln — nicht während der Nutzer tippt (Cursor-Sprünge vermeiden).
    effect(() => {
      const v = this.value();
      const el = this.editorRef()?.nativeElement;
      if (el && !this.showSource() && !this.editing && el.innerHTML !== v) {
        el.innerHTML = v;
      }
    });
  }

  onInput(): void {
    const el = this.editorRef()?.nativeElement;
    if (el) {
      this.editing = true;
      this.value.set(el.innerHTML);
    }
  }

  onBlur(): void {
    this.editing = false;
  }

  exec(command: string, argument?: string): void {
    this.editorRef()?.nativeElement.focus();
    try {
      document.execCommand(command, false, argument);
    } catch {
      // execCommand ist deprecated, in aktuellen Browsern aber funktional.
    }
    this.syncFromDom();
  }

  createLink(): void {
    const url = window.prompt('Link-Adresse (URL)');
    if (url) {
      this.exec('createLink', url);
    }
  }

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
    if (this.showSource()) {
      this.value.set((this.value() ?? '') + token);
      return;
    }
    const el = this.editorRef()?.nativeElement;
    if (el && document.activeElement === el) {
      document.execCommand('insertText', false, token);
      this.syncFromDom();
    } else if (el) {
      el.innerHTML = (el.innerHTML ?? '') + token;
      this.value.set(el.innerHTML);
    } else {
      this.value.set((this.value() ?? '') + token);
    }
  }

  toggleSource(): void {
    this.showSource.update((v) => !v);
  }

  private syncFromDom(): void {
    const el = this.editorRef()?.nativeElement;
    if (el) {
      this.value.set(el.innerHTML);
    }
  }
}
