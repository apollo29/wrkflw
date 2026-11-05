import { provideHttpClient } from '@angular/common/http';
import { Component, signal, viewChild } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideWorkflowAdmin } from '../../config/config';
import { emailifyHtml, previewHtml } from './email-extensions';
import { safeHref, safeImageSrc, WfHtmlEditor } from './html-editor';

// jsdom misst nichts; ProseMirror fragt beim Scrollen zur Auswahl nach Rechtecken.
const emptyRects = (): DOMRectList =>
  Object.assign([], { item: () => null }) as unknown as DOMRectList;
const emptyRect = (): DOMRect =>
  ({
    x: 0,
    y: 0,
    width: 0,
    height: 0,
    top: 0,
    left: 0,
    right: 0,
    bottom: 0,
    toJSON: () => ({}),
  }) as DOMRect;
for (const proto of [
  Range.prototype,
  Text.prototype as unknown as Range,
  Element.prototype as unknown as Range,
]) {
  proto.getClientRects ??= emptyRects;
  proto.getBoundingClientRect ??= emptyRect;
}

@Component({
  imports: [WfHtmlEditor],
  template: `<wf-html-editor
    label="Inhalt"
    [(value)]="html"
    [placeholders]="['antrag_id']"
    [sampleValues]="{ antrag_id: 'A-1024' }"
  />`,
})
class Host {
  readonly html = signal('<p>Hallo {{name}}</p>');
  readonly editor = viewChild.required(WfHtmlEditor);
}

function setup(uploadImage?: (f: File) => Promise<string>) {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideWorkflowAdmin({ apiBaseUrl: '/api', ...(uploadImage ? { uploadImage } : {}) }),
    ],
  });
  const fixture = TestBed.createComponent(Host);
  return { fixture, el: fixture.nativeElement as HTMLElement };
}

const click = (el: HTMLElement, text: string): void =>
  [...el.querySelectorAll('button')]
    .find((b) => b.textContent?.trim() === text || b.getAttribute('aria-label') === text)!
    .click();

describe('WfHtmlEditor', () => {
  it('accepts only safe link and image targets', () => {
    expect(safeHref('https://example.org')).toBe('https://example.org');
    expect(safeHref('mailto:a@b.ch')).toBe('mailto:a@b.ch');
    expect(safeHref('{{link}}')).toBe('{{link}}');
    expect(safeHref('javascript:alert(1)')).toBeNull();
    expect(safeImageSrc('https://cdn.example.org/a.png')).toBe('https://cdn.example.org/a.png');
    expect(safeImageSrc('/demo-bild.png')).toBe('/demo-bild.png');
    expect(safeImageSrc('data:image/png;base64,AAA')).toBeNull();
    expect(safeImageSrc('http://unsicher.example.org/a.png')).toBeNull();
  });

  it('makes tables e-mail-safe: inline borders, padding and widths in percent (E-48)', () => {
    const html = emailifyHtml(
      '<table style="min-width: 75px"><colgroup><col></colgroup><tbody><tr><th><p>A</p></th><th><p>B</p></th></tr><tr><td><p>{{x}}</p></td><td><p>2</p></td></tr></tbody></table>',
    );
    expect(html).not.toContain('colgroup');
    const table = new DOMParser().parseFromString(html, 'text/html').querySelector('table')!;
    expect(table.getAttribute('width')).toBe('100%');
    expect(table.getAttribute('style')).toBe('width:100%;border-collapse:collapse;');
    expect(html).toContain(
      '<th style="border:1px solid #d4d4d9;padding:8px 10px;vertical-align:top;text-align:left;background:#f4f4f5;width:50%;">',
    );
    expect(html).toContain('{{x}}');
    expect(
      emailifyHtml('<table role="presentation"><tbody><tr><td>x</td></tr></tbody></table>'),
    ).toBe('<table role="presentation"><tbody><tr><td>x</td></tr></tbody></table>');
  });

  it('fills the preview with escaped sample values (E-45)', () => {
    expect(
      previewHtml('<p>{{a}} {{ b }}</p>', { a: '<b>x</b>' }, (n) => `[Beispielwert ${n}]`),
    ).toBe('<p>&lt;b&gt;x&lt;/b&gt; [Beispielwert b]</p>');
  });

  it('inserts a 3×3 table with header, shows the table group and changes rows and columns (E-47)', async () => {
    const { fixture, el } = setup();
    await fixture.whenStable();
    expect(el.querySelector('[data-testid="html-tablebar"]')).toBeNull();
    click(el, 'Tabelle');
    await fixture.whenStable();
    const html = () => fixture.componentInstance.html();
    expect((html().match(/<tr>/g) ?? []).length).toBe(3);
    expect((html().match(/<th /g) ?? []).length).toBe(3);
    expect(html()).toContain('width:33.33%');
    expect(el.querySelector('[data-testid="html-tablebar"]')).not.toBeNull();

    click(el, 'Spalte +');
    click(el, 'Zeile +');
    await fixture.whenStable();
    expect((html().match(/<tr>/g) ?? []).length).toBe(4);
    expect(html()).toContain('width:25%');

    click(el, 'Tabelle entfernen');
    await fixture.whenStable();
    expect(html()).not.toContain('<table');
  });

  it('inserts placeholders from the list and custom ones, and a button without browser dialogs (9.14)', async () => {
    const prompt = vi.spyOn(window, 'prompt');
    const { fixture, el } = setup();
    await fixture.whenStable();
    expect(el.querySelector('.ProseMirror')?.textContent).toContain('Hallo {{name}}');

    const select = el.querySelector<HTMLSelectElement>('[data-testid="html-placeholder"]')!;
    select.value = 'antrag_id';
    select.dispatchEvent(new Event('change'));
    const custom = el.querySelector<HTMLInputElement>('[data-testid="html-custom"]')!;
    custom.value = 'nicht gültig';
    custom.dispatchEvent(new Event('input'));
    custom.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    custom.value = 'betrag';
    custom.dispatchEvent(new Event('input'));
    custom.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
    await fixture.whenStable();
    expect(fixture.componentInstance.html()).toContain('{{antrag_id}}');
    expect(fixture.componentInstance.html()).toContain('{{betrag}}');
    expect(fixture.componentInstance.html()).not.toContain('nicht gültig');

    click(el, 'Button');
    await fixture.whenStable();
    const text = el.querySelector<HTMLInputElement>('[data-testid="html-bar-text"]')!;
    const url = el.querySelector<HTMLInputElement>('[data-testid="html-bar-url"]')!;
    text.value = 'Zum Antrag';
    text.dispatchEvent(new Event('input'));
    url.value = 'javascript:alert(1)';
    url.dispatchEvent(new Event('input'));
    el.querySelector('form')!.dispatchEvent(new Event('submit'));
    await fixture.whenStable();
    expect(el.querySelector('[role="alert"]')).not.toBeNull();
    url.value = 'https://example.org/a';
    url.dispatchEvent(new Event('input'));
    el.querySelector('form')!.dispatchEvent(new Event('submit'));
    await fixture.whenStable();
    expect(fixture.componentInstance.html()).toContain('href="https://example.org/a"');
    expect(prompt).not.toHaveBeenCalled();
  });

  it('shows the image upload only with uploadImage and inserts the returned URL', async () => {
    const plain = setup();
    await plain.fixture.whenStable();
    expect(plain.el.querySelector('[data-testid="html-image-file"]')).toBeNull();
    TestBed.resetTestingModule();

    const upload = vi.fn().mockResolvedValue('https://cdn.example.org/bild.png');
    const { fixture, el } = setup(upload);
    await fixture.whenStable();
    const input = el.querySelector<HTMLInputElement>('[data-testid="html-image-file"]')!;
    Object.defineProperty(input, 'files', {
      value: [new File(['x'], 'bild.png')],
      configurable: true,
    });
    input.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    await new Promise((r) => setTimeout(r));
    expect(upload).toHaveBeenCalled();
    expect(fixture.componentInstance.html()).toContain('src="https://cdn.example.org/bild.png"');
  });

  it('edits the HTML source and shows the preview', async () => {
    const { fixture, el } = setup();
    await fixture.whenStable();
    el.querySelector<HTMLButtonElement>('[data-testid="html-source-toggle"]')!.click();
    await fixture.whenStable();
    const source = el.querySelector<HTMLTextAreaElement>('[data-testid="html-source"]')!;
    expect(source.value).toContain('Hallo {{name}}');
    source.value = '<h2>Neu {{antrag_id}}</h2>';
    source.dispatchEvent(new Event('input'));
    el.querySelector<HTMLButtonElement>('[data-testid="html-source-toggle"]')!.click();
    await fixture.whenStable();
    expect(fixture.componentInstance.html()).toContain('<h2>Neu {{antrag_id}}</h2>');

    el.querySelector<HTMLButtonElement>('[data-testid="html-preview-toggle"]')!.click();
    await fixture.whenStable();
    const frame = el.querySelector<HTMLIFrameElement>('[data-testid="html-preview-frame"]')!;
    expect(frame.getAttribute('sandbox')).toBe('allow-same-origin');
    expect(frame.getAttribute('srcdoc')).toContain('<h2>Neu A-1024</h2>');
  });
});
