import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { DomSanitizer } from '@angular/platform-browser';

/** SVG-Inhalte (viewBox 0 0 16 16) aus dem Design-Canvas. */
export const WF_ICONS = {
  automatic: '<path d="M9 1.5 3.5 9H8l-1 5.5L12.5 7H8z"/>',
  interactive:
    '<rect x="2.5" y="2" width="11" height="12" rx="1.5"/><path d="M5 5.5h6M5 8h6M5 10.5h3.5"/>',
  timer: '<circle cx="8" cy="8" r="6"/><path d="M8 4.8V8l2.3 1.5"/>',
  workflow: '<circle cx="3.8" cy="8" r="2.2"/><circle cx="12.2" cy="8" r="2.2"/><path d="M6 8h4"/>',
  'data-check':
    '<ellipse cx="8" cy="3.8" rx="5" ry="1.9"/><path d="M3 3.8v8.4c0 1 2.2 1.9 5 1.9s5-.9 5-1.9V3.8M3 8c0 1 2.2 1.9 5 1.9s5-.9 5-1.9"/>',
  'data-write':
    '<ellipse cx="9.5" cy="3.8" rx="4.5" ry="1.8"/><path d="M5 3.8v8.4c0 1 2 1.8 4.5 1.8s4.5-.8 4.5-1.8V3.8M1 9h5.5M4.5 7l2 2-2 2"/>',
  search: '<circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5 14 14"/>',
  chevron: '<path d="M4 6l4 4 4-4"/>',
  arrow: '<path d="M1.5 8h12M10 4.5 13.5 8 10 11.5"/>',
  undo: '<path d="M5 3 2 6l3 3M2 6h7.5a4 4 0 0 1 0 8H7"/>',
  redo: '<path d="M11 3l3 3-3 3M14 6H6.5a4 4 0 0 0 0 8H9"/>',
  close: '<path d="M4 4l8 8M12 4l-8 8"/>',
  plus: '<path d="M8 3v10M3 8h10"/>',
  'align-left': '<path d="M2 4h12M2 8h8M2 12h11"/>',
  'align-center': '<path d="M2 4h12M4 8h8M3 12h10"/>',
  'align-right': '<path d="M2 4h12M6 8h8M3 12h11"/>',
  'clear-format': '<path d="M4 3h9M8.5 3 6 13M3 13h5M11 10l3 3M14 10l-3 3"/>',
} as const;

export type WfIconName = keyof typeof WF_ICONS;

/** Icon in Textfarbe, 16 px, Strich 1.4. Rein dekorativ (`aria-hidden`). */
@Component({
  selector: 'wf-icon',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { 'aria-hidden': 'true', '[style.--wf-icon-size.px]': 'size()' },
  template: `<svg viewBox="0 0 16 16" focusable="false" [innerHTML]="markup()"></svg>`,
  styles: `
    :host {
      display: inline-flex;
      flex: none;
      width: var(--wf-icon-size);
      height: var(--wf-icon-size);
    }
    svg {
      width: 100%;
      height: 100%;
      fill: none;
      stroke: currentColor;
      stroke-width: 1.4;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
  `,
})
export class WfIcon {
  readonly name = input.required<WfIconName>();
  readonly size = input(16);
  private readonly sanitizer = inject(DomSanitizer);
  // Nur feste Inhalte aus WF_ICONS, nie Eingaben von aussen.
  protected readonly markup = computed(() =>
    this.sanitizer.bypassSecurityTrustHtml(WF_ICONS[this.name()]),
  );
}
