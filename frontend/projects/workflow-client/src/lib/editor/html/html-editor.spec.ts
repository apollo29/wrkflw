import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { safeHref, WfHtmlEditor } from './html-editor';

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
for (const proto of [Range.prototype, Text.prototype as unknown as Range]) {
  proto.getClientRects ??= emptyRects;
  proto.getBoundingClientRect ??= emptyRect;
}

@Component({
  imports: [WfHtmlEditor],
  template: `<wf-html-editor label="Inhalt" [(value)]="html" [placeholders]="['antrag_id']" />`,
})
class Host {
  readonly html = signal('<p>Hallo {{name}}</p>');
}

describe('WfHtmlEditor', () => {
  it('accepts only safe link targets', () => {
    expect(safeHref('https://example.org')).toBe('https://example.org');
    expect(safeHref('mailto:a@b.ch')).toBe('mailto:a@b.ch');
    expect(safeHref('{{link}}')).toBe('{{link}}');
    expect(safeHref('javascript:alert(1)')).toBeNull();
    expect(safeHref('  ')).toBeNull();
  });

  it('shows the value, inserts placeholders and a button without browser dialogs (9.14)', async () => {
    const prompt = vi.spyOn(window, 'prompt');
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('.ProseMirror')?.textContent).toContain('Hallo {{name}}');

    const select = el.querySelector<HTMLSelectElement>('select')!;
    select.value = 'antrag_id';
    select.dispatchEvent(new Event('change'));
    await fixture.whenStable();
    expect(fixture.componentInstance.html()).toContain('{{antrag_id}}');

    [...el.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Knopf')!.click();
    await fixture.whenStable();
    const text = el.querySelector<HTMLInputElement>('[data-testid="html-bar-text"]')!;
    const url = el.querySelector<HTMLInputElement>('[data-testid="html-bar-url"]')!;
    text.value = 'Zum Antrag';
    text.dispatchEvent(new Event('input'));
    url.value = 'https://example.org/a';
    url.dispatchEvent(new Event('input'));
    el.querySelector('form')!.dispatchEvent(new Event('submit'));
    await fixture.whenStable();

    expect(fixture.componentInstance.html()).toContain('data-email-button');
    expect(fixture.componentInstance.html()).toContain('href="https://example.org/a"');
    expect(prompt).not.toHaveBeenCalled();

    fixture.componentInstance.html.set('<p>Neu</p>');
    await fixture.whenStable();
    expect(el.querySelector('.ProseMirror')?.textContent).toBe('Neu');
  });
});
