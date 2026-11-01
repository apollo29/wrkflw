import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { WfField } from './field';

@Component({
  imports: [WfField],
  template: `<wf-field label="Name" [hint]="hint()" [error]="error()"
    ><input class="wf-inp"
  /></wf-field>`,
})
class Host {
  readonly hint = signal<string | null>('Kurz und klar.');
  readonly error = signal<string | null>(null);
}

describe('WfField', () => {
  it('links label, hint and error to the projected input', async () => {
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const input = el.querySelector('input') as HTMLInputElement;
    const label = el.querySelector('label') as HTMLLabelElement;

    expect(input.id).not.toBe('');
    expect(label.htmlFor).toBe(input.id);
    const hintId = input.getAttribute('aria-describedby') ?? '';
    expect(el.querySelector(`#${hintId}`)?.textContent).toContain('Kurz und klar.');
    expect(input.hasAttribute('aria-invalid')).toBe(false);

    fixture.componentInstance.error.set('Pflichtfeld.');
    await fixture.whenStable();
    expect(input.getAttribute('aria-invalid')).toBe('true');
    const ids = (input.getAttribute('aria-describedby') ?? '').split(' ');
    expect(ids).toHaveLength(2);
    expect(el.querySelector(`#${ids[1]}`)?.textContent).toContain('Pflichtfeld.');

    fixture.componentInstance.error.set(null);
    fixture.componentInstance.hint.set(null);
    await fixture.whenStable();
    expect(input.hasAttribute('aria-invalid')).toBe(false);
    expect(input.hasAttribute('aria-describedby')).toBe(false);
  });
});
