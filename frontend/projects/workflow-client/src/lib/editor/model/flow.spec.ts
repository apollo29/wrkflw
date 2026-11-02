import { fromDefinition, previewFlow, PreviewNode } from './definition-mapping';
import { flowSections, laneLabel } from './flow';

describe('flow', () => {
  it('shows the button label instead of SONST for an exit bound only to a button (9.14)', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'frage',
      steps: {
        frage: {
          type: 'interactive',
          transitions: [
            { to: 'ja', event: 'submit', label: 'Freigeben' },
            { to: 'nein', event: 'ablehnen' },
            { to: 'pruefen', event: 'submit', when: "context['betrag'] > 1000" },
          ],
        },
        ja: { transitions: [] },
        nein: { transitions: [] },
        pruefen: { transitions: [] },
      },
    });
    const fork = previewFlow(model).find(
      (n): n is Extract<PreviewNode, { kind: 'fork' }> => n.kind === 'fork',
    );
    expect(fork?.lanes.map(laneLabel)).toEqual([
      { kind: 'button', text: 'Freigeben' },
      { kind: 'button', text: 'ablehnen' },
      { kind: 'condition', text: "context['betrag'] > 1000" },
    ]);
  });

  it('keeps SONST for unconditional exits of background steps', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'laden',
      steps: {
        laden: {
          action: 'check_data',
          transitions: [{ to: 'a', when: "context['x'] > 1" }, { to: 'b' }],
        },
        a: { transitions: [{ to: 'c' }] },
        b: { transitions: [{ to: 'c' }] },
        c: { transitions: [] },
      },
    });
    const fork = previewFlow(model)[0];
    expect(fork?.kind === 'fork' && fork.lanes.map(laneLabel)).toEqual([
      { kind: 'condition', text: "context['x'] > 1" },
      { kind: 'else', text: '' },
    ]);
  });

  it('groups the preview into chains and forks with prev, tail and continues (E-11 to E-15)', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'start',
      steps: {
        start: { transitions: [{ to: 'laden' }] },
        laden: { transitions: [{ to: 'a', when: "context['x'] > 1" }, { to: 'b' }] },
        a: { transitions: [{ to: 'merge' }] },
        b: { transitions: [{ to: 'merge' }] },
        merge: { transitions: [{ to: 'ende' }] },
        ende: { transitions: [] },
        lose: { transitions: [] },
      },
    });
    const sections = flowSections(model);
    // Der letzte Schritt vor der Verzweigung steht links im Bild der Verzweigung (E-12).
    expect(sections).toEqual([
      {
        kind: 'fork',
        prev: 'start',
        from: 'laden',
        lanes: [
          { label: { kind: 'condition', text: "context['x'] > 1" }, steps: ['a'] },
          { label: { kind: 'else', text: '' }, steps: ['b'] },
        ],
        merge: 'merge',
        tail: ['ende'],
        continues: false,
      },
      { kind: 'unreachable', steps: ['lose'] },
    ]);
  });

  it('marks a fork that is followed directly by the next one', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'a',
      steps: {
        a: { transitions: [{ to: 'b', when: 'x' }, { to: 'c' }] },
        b: { transitions: [{ to: 'm' }] },
        c: { transitions: [{ to: 'm' }] },
        m: { transitions: [{ to: 'd', when: 'y' }, { to: 'e' }] },
        d: { transitions: [] },
        e: { transitions: [] },
      },
    });
    const sections = flowSections(model);
    expect(sections.map((s) => s.kind)).toEqual(['fork', 'fork']);
    expect(sections[0]).toMatchObject({ prev: null, merge: 'm', tail: [], continues: true });
    expect(sections[1]).toMatchObject({ prev: null, from: 'm', merge: null });
  });

  it('is empty without steps', () => {
    expect(flowSections(fromDefinition({ id: 'f', steps: {} }))).toEqual([]);
  });
});
