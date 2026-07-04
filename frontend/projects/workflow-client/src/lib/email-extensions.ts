import { Extension, mergeAttributes, Node } from '@tiptap/core';
import { Plugin } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

/**
 * Zeigt `{{platzhalter}}` im Editor als hervorgehobene Chips an — rein visuell über
 * ProseMirror-Decorations. Der Dokumentinhalt (und damit `getHTML()`) bleibt der
 * reine Text `{{feld}}`, den die Engine beim Versand ersetzt.
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
                  Decoration.inline(from, from + match[0].length, { class: 'wfb-ph' }),
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

/**
 * E-Mail-tauglicher Call-to-Action-Button (atomarer Block). renderHTML erzeugt einen
 * inline-gestylten Anchor, sodass `getHTML()` in E-Mail-Clients funktioniert.
 */
export const EmailButton = Node.create({
  name: 'emailButton',
  group: 'block',
  atom: true,
  draggable: true,
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
    const style =
      'display:inline-block;padding:12px 22px;background:#2f6feb;color:#ffffff;' +
      'border-radius:6px;text-decoration:none;font-weight:600;';
    return ['a', mergeAttributes(HTMLAttributes, { 'data-email-button': '', style }), node.attrs['label']];
  },
});

/** Eine Spalte innerhalb von {@link Columns} — rendert als `<td>` (E-Mail-Layout). */
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

/**
 * Zwei-Spalten-Layout als E-Mail-sichere Tabelle (`role=presentation`). Enthält genau
 * zwei {@link Column}-Knoten, jeweils frei editierbar.
 */
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
