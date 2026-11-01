import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { WfSegment, WfSegmentOption } from './segment';

@Component({
  imports: [WfSegment],
  template: `<wf-segment label="Status" [options]="options" [(value)]="value" />`,
})
class Host {
  readonly options: WfSegmentOption[] = [
    { value: 'active', label: 'A', ok: true },
    { value: 'draft', label: 'B' },
    { value: 'inactive', label: 'C', disabled: true },
  ];
  readonly value = signal('active');
}

describe('WfSegment', () => {
  it('works like a radio group', async () => {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const group = el.querySelector('wf-segment') as HTMLElement;
    const radios = [...el.querySelectorAll<HTMLButtonElement>('[role="radio"]')];

    expect(group.getAttribute('role')).toBe('radiogroup');
    expect(group.getAttribute('aria-label')).toBe('Status');
    expect(radios.map((r) => r.getAttribute('aria-checked'))).toEqual(['true', 'false', 'false']);
    expect(radios.map((r) => r.tabIndex)).toEqual([0, -1, -1]);
    expect(radios[0]?.classList).toContain('wf-seg__item--ok');

    group.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    await fixture.whenStable();
    expect(fixture.componentInstance.value()).toBe('draft');

    // Deaktivierte Option wird übersprungen; am Ende geht es von vorne los.
    group.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    await fixture.whenStable();
    expect(fixture.componentInstance.value()).toBe('active');

    radios[1]?.click();
    await fixture.whenStable();
    expect(fixture.componentInstance.value()).toBe('draft');
  });
});
