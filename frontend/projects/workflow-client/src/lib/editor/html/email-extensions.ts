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
