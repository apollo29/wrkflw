import { Extension, mergeAttributes, Node } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * Zeigt `{{platzhalter}}` als Chips an, rein visuell. Der Inhalt (und `getHTML()`) bleibt
 * der Text `{{feld}}`, den die Engine beim Versand ersetzt. Port aus 2.x.
 */
export const PlaceholderHighlight = Extension.create({
  name: 'placeholderHighlight',
  addProseMirrorPlugins() {
    return [
      new Plugin({
        props: {
          decorations(state) {
            const decorations: Decoration[] = [];
            state.doc.descendants((node, pos) => {
              if (!node.isText || typeof node.text !== 'string') {
                return;
              }
              const regex = /\{\{[\w.]+\}\}/g;
              let match: RegExpExecArray | null;
              while ((match = regex.exec(node.text)) !== null) {
                const from = pos + match.index;
                decorations.push(
                  Decoration.inline(from, from + match[0].length, { class: 'wf-ph' }),
                );
              }
            });
            return DecorationSet.create(state.doc, decorations);
          },
        },
      }),
    ];
  },
});

/** Inline gestylte Farbe des Knopfs in E-Mails; Tokens wirken in Mail-Clients nicht. */
export const EMAIL_BUTTON_STYLE =
  'display:inline-block;padding:12px 22px;background:#4338ca;color:#ffffff;border-radius:6px;text-decoration:none;font-weight:600;';

/**
 * E-Mail-tauglicher Knopf (atomarer Block). `getHTML()` liefert einen inline gestylten Link,
 * der auch in E-Mail-Clients funktioniert. Port aus 2.x.
 */
export const EmailButton = Node.create({
  name: 'emailButton',
  group: 'block',
  atom: true,
  selectable: true,

  addAttributes() {
    return {
      label: {
        default: 'Button',
        parseHTML: (el) => el.textContent ?? 'Button',
        renderHTML: () => ({}),
      },
      href: {
        default: '#',
        parseHTML: (el) => el.getAttribute('href') ?? '#',
      },
    };
  },

  parseHTML() {
    return [{ tag: 'a[data-email-button]' }];
  },

  renderHTML({ node, HTMLAttributes }) {
    return [
      'a',
      mergeAttributes(HTMLAttributes, { 'data-email-button': '', style: EMAIL_BUTTON_STYLE }),
      String(node.attrs['label']),
    ];
  },
});

/** Eine Spalte von {@link Columns} – `<td>` im E-Mail-Layout (Port aus 2.x). */
export const Column = Node.create({
  name: 'column',
  content: 'block+',
  isolating: true,
  parseHTML() {
    return [{ tag: 'td[data-column]' }];
  },
  renderHTML() {
    return ['td', { 'data-column': '', style: 'width:50%;vertical-align:top;padding:0 8px;' }, 0];
  },
});

/** «2 Spalten» als E-Mail-sichere Tabelle (`role=presentation`, E-46). */
export const Columns = Node.create({
  name: 'columns',
  group: 'block',
  content: 'column column',
  isolating: true,
  parseHTML() {
    return [{ tag: 'table[data-columns]' }];
  },
  renderHTML() {
    return [
      'table',
      {
        'data-columns': '',
        role: 'presentation',
        width: '100%',
        style: 'width:100%;border-collapse:collapse;',
      },
      ['tbody', {}, ['tr', {}, 0]],
    ];
  },
});

export const EMAIL_TABLE_STYLE = 'width:100%;border-collapse:collapse;';
export const EMAIL_CELL_STYLE =
  'border:1px solid #d4d4d9;padding:8px 10px;vertical-align:top;text-align:left;';
export const EMAIL_HEADER_BG = 'background:#f4f4f5;';

/**
 * Macht Tabellen E-Mail-tauglich (E-48): Rahmen und Abstände stehen direkt an den Zellen,
 * Spaltenbreiten in Prozent; die `colgroup` des Editors fällt weg. «2 Spalten» und andere
 * Layout-Tabellen (`role=presentation`) bleiben unverändert.
 */
export function emailifyHtml(html: string): string {
  if (!html.includes('<table') || typeof DOMParser === 'undefined') {
    return html;
  }
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  for (const table of Array.from(doc.querySelectorAll('table:not([role="presentation"])'))) {
    table.querySelectorAll('colgroup').forEach((c) => c.remove());
    table.setAttribute('width', '100%');
    table.setAttribute('cellpadding', '0');
    table.setAttribute('cellspacing', '0');
    table.setAttribute('style', EMAIL_TABLE_STYLE);
    for (const row of Array.from(table.querySelectorAll('tr'))) {
      const cells = Array.from(row.children).filter(
        (c) => c.tagName === 'TD' || c.tagName === 'TH',
      );
      const width = cells.length > 0 ? `${Math.round((100 / cells.length) * 100) / 100}%` : '';
      for (const cell of cells) {
        cell.removeAttribute('colwidth');
        cell.setAttribute(
          'style',
          EMAIL_CELL_STYLE + (cell.tagName === 'TH' ? EMAIL_HEADER_BG : '') + `width:${width};`,
        );
      }
    }
  }
  return doc.body.innerHTML;
}

/**
 * Ganzes Dokument für die Vorschau im abgeschotteten `iframe`: weiss wie ein
 * Mailprogramm, damit Inline-Stile (Rahmen, Breiten) genau so erscheinen wie beim Empfänger.
 */
export function previewDocument(body: string): string {
  return (
    '<!doctype html><html><head><meta charset="utf-8"><style>' +
    'body{margin:0;padding:12px 14px;font:14px/1.5 system-ui,sans-serif;color:#18181b;background:#fff;overflow-wrap:anywhere}' +
    'p{margin:0 0 8px}img{max-width:100%;height:auto}a{color:#4f46e5}' +
    '</style></head><body>' +
    body +
    '</body></html>'
  );
}

/** Platzhalter mit Beispielwerten für die Vorschau (E-45); Werte werden escaped. */
export function previewHtml(
  html: string,
  samples: Readonly<Record<string, string>>,
  unknown: (name: string) => string,
): string {
  const escape = (v: string): string =>
    v.replace(
      /[&<>"']/g,
      (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] ?? c,
    );
  return html.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (_, name: string) =>
    escape(samples[name] ?? unknown(name)),
  );
}
