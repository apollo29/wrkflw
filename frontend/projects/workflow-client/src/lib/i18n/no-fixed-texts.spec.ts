/*
 * Regel aus CLAUDE.md: Jeder Text der Oberfläche kommt aus dem Katalog. Dieser Test sucht
 * in den Templates der Library nach Wörtern, die nach festem deutschem Text aussehen.
 */
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

/** Tests laufen im Ordner `frontend`. */
const LIB = join(process.cwd(), 'projects/workflow-client/src/lib');

function sources(dir: string): [string, string][] {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry): [string, string][] => {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      return sources(path);
    }
    return entry.name.endsWith('.ts') || entry.name.endsWith('.html')
      ? [[path.slice(LIB.length + 1), readFileSync(path, 'utf8')]]
      : [];
  });
}

/** Inhalt der `template:`-Literale einer Datei. */
function inlineTemplates(source: string): string[] {
  return [...source.matchAll(/template:\s*`([\s\S]*?)`/g)].map((m) => m[1] ?? '');
}

/** Text zwischen Tags, ohne Bindungen und Control-Flow. */
function visibleText(template: string): string[] {
  return template
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/\{\{[\s\S]*?\}\}/g, ' ')
    .replace(/@let [^;]*;/g, ' ')
    .replace(/@\w+[^{]*\{|\}/g, ' ')
    .split(/<[^>]+>/)
    .map((s) => s.trim())
    .filter((s) => /[A-Za-zÄÖÜäöüß]{2,}/.test(s));
}

describe('keine festen Texte in Templates', () => {
  const files = sources(LIB).filter(([path]) => !path.endsWith('.spec.ts'));

  it('detects fixed text', () => {
    expect(visibleText('<p>Hallo {{ name }}</p> @if (x) { <b>{{ "a" | wfT }}</b> }')).toEqual([
      'Hallo',
    ]);
  });

  it('finds the library sources', () => {
    expect(files.length).toBeGreaterThan(5);
  });

  for (const [path, source] of files) {
    for (const template of path.endsWith('.html') ? [source] : inlineTemplates(source)) {
      it(`${path} uses only catalog keys`, () => {
        expect(visibleText(template)).toEqual([]);
      });
    }
  }
});
