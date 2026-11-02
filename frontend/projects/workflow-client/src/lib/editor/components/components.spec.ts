import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, inject } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideWorkflowAdmin } from '../../config/config';
import { fromDefinition } from '../model/definition-mapping';
import { EditorStore } from '../state/editor-store';
import { WfActionFields } from './action-fields';
import { WfEditorSide } from './side-panel';
import { WfStepDetail } from './step-detail';

@Component({
  imports: [WfEditorSide, WfStepDetail],
  providers: [EditorStore],
  template: `<wf-editor-side /><wf-step-detail />`,
})
class Host {
  readonly store = inject(EditorStore);
}

function setup(definition: Record<string, unknown>, selected: string) {
  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideWorkflowAdmin({ apiBaseUrl: '/api' }),
    ],
  });
  const fixture = TestBed.createComponent(Host);
  const store = fixture.componentInstance.store;
  store.model.set(fromDefinition(definition));
  store.selected.set(selected);
  return { fixture, store, el: fixture.nativeElement as HTMLElement };
}

function type(input: HTMLInputElement | HTMLSelectElement, value: string): void {
  input.value = value;
  input.dispatchEvent(new Event(input instanceof HTMLSelectElement ? 'change' : 'input'));
}

const FLOW = {
  id: 'f',
  startStep: 'laden',
  steps: {
    laden: { action: 'check_data', transitions: [{ to: 'ja' }] },
    ja: { type: 'interactive', transitions: [{ to: 'ende', event: 'submit', label: 'Weiter' }] },
    ende: { action: 'send_email', transitions: [] },
  },
};

describe('editor components', () => {
  it('«Bedingung…» opens the assistant, which builds the expression (9.14, E-26)', async () => {
    const { fixture, store, el } = setup(FLOW, 'laden');
    await fixture.whenStable();

    expect(el.querySelector('[data-testid="transition-else"]')).not.toBeNull();
    const button = [...el.querySelectorAll('button')].find((b) =>
      b.textContent?.includes('Bedingung…'),
    )!;
    button.click();
    await fixture.whenStable();

    expect(el.querySelector('[data-testid="transition-card"]')).not.toBeNull();
    type(el.querySelector<HTMLInputElement>('[data-testid="condition-field"]')!, 'antrag_betrag');
    type(el.querySelector<HTMLSelectElement>('select[aria-label="Vergleich"]')!, '>');
    type(el.querySelector<HTMLInputElement>('[data-testid="condition-value"]')!, '1000');
    await fixture.whenStable();

    expect(el.querySelector('[data-testid="condition-expression"]')?.textContent?.trim()).toBe(
      "context['antrag_betrag'] > 1000",
    );
    expect(store.model().steps[0]?.transitions[0]?.condition).toEqual({
      field: 'antrag_betrag',
      op: '>',
      value: '1000',
    });
  });

  it('shows button fields and the button preview at interactive steps (E-29)', async () => {
    const { fixture, el } = setup(FLOW, 'ja');
    await fixture.whenStable();
    expect(el.querySelector<HTMLInputElement>('input[aria-label="Ereignis"]')?.value).toBe(
      'submit',
    );
    expect(el.querySelector('.wf-ed-preview-btn')?.textContent?.trim()).toBe('Weiter');
  });

  it('selects steps with the arrow keys and deletes with Delete (9.14)', async () => {
    const { fixture, store, el } = setup(FLOW, 'laden');
    await fixture.whenStable();
    const list = el.querySelector<HTMLElement>('[data-testid="step-list"]')!;
    expect(
      [...list.querySelectorAll('[role="option"]')].map((o) => o.getAttribute('data-step')),
    ).toEqual(['laden', 'ja', 'ende']);

    list.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown' }));
    expect(store.selected()).toBe('ja');
    list.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
    list.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp' }));
    expect(store.selected()).toBe('laden');
    list.dispatchEvent(new KeyboardEvent('keydown', { key: 'Delete' }));
    expect(store.steps().map((s) => s.name)).toEqual(['ja', 'ende']);
  });

  it('renames on blur and rejects duplicate names at the field', async () => {
    const { fixture, store, el } = setup(FLOW, 'ja');
    await fixture.whenStable();
    const name = el.querySelector<HTMLInputElement>('[data-testid="step-name"]')!;
    type(name, 'ende');
    name.dispatchEvent(new Event('blur'));
    await fixture.whenStable();
    expect(el.querySelector('[role="alert"]')?.textContent).toContain(
      'Diesen Namen hat schon ein anderer Schritt.',
    );
    expect(name.getAttribute('aria-invalid')).toBe('true');

    type(name, 'pruefen');
    name.dispatchEvent(new Event('blur'));
    await fixture.whenStable();
    expect(store.model().steps[0]?.transitions[0]?.to).toBe('pruefen');
  });

  it('renders action fields by type and hides subject/body when a template is chosen', async () => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideWorkflowAdmin({ apiBaseUrl: '/api' }),
        EditorStore,
      ],
    });
    const store = TestBed.inject(EditorStore);
    store.model.set(fromDefinition(FLOW));
    store.catalogs.set({
      ...store.catalogs(),
      actions: [
        {
          key: 'send_email',
          label: 'E-Mail senden',
          fields: [
            { name: 'templateId', label: 'Vorlage (optional)', type: 'template-ref' },
            { name: 'to', label: 'An', type: 'text' },
            { name: 'subject', label: 'Betreff', type: 'text' },
            { name: 'body', label: 'Inhalt (HTML)', type: 'html' },
            { name: 'copy', label: 'Kopie an mich', type: 'boolean' },
          ],
        },
      ],
      templates: [
        { id: 'eingang', name: 'Eingang', type: 'email' },
        { id: 'seite', name: 'Seite', type: 'page' },
      ],
    });
    const fixture = TestBed.createComponent(WfActionFields);
    fixture.componentRef.setInput('step', store.model().steps[2]);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;

    expect(
      [...el.querySelectorAll('label, .wf-field-label')].map((l) => l.textContent?.trim()),
    ).toEqual(
      expect.arrayContaining([
        'Vorlage (optional)',
        'An',
        'Betreff',
        'Inhalt (HTML)',
        'Kopie an mich',
      ]),
    );
    expect([...el.querySelectorAll('select option')].map((o) => o.textContent?.trim())).toEqual([
      '— eigenes (inline) —',
      'Eingang (eingang)',
    ]);

    type(el.querySelector('select')!, 'eingang');
    fixture.componentRef.setInput('step', store.model().steps[2]);
    await fixture.whenStable();
    expect(store.model().steps[2]?.config).toEqual({ templateId: 'eingang' });
    const labels = [...el.querySelectorAll('label, .wf-field-label')].map((l) =>
      l.textContent?.trim(),
    );
    expect(labels).not.toContain('Betreff');
    expect(labels).not.toContain('Inhalt (HTML)');
    expect(el.textContent).toContain('Die Vorlage überschreibt Betreff und Inhalt beim Versand.');
  });
});
