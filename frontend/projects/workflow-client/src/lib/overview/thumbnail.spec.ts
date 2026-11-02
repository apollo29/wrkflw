import type { DefinitionStructure } from '../api/models';
import { thumbnailLayout } from './thumbnail';

const linear: DefinitionStructure = {
  startStep: 'a',
  steps: [
    {
      name: 'a',
      kind: 'interactive',
      transitions: [{ to: 'b', conditional: false, event: 'submit' }],
    },
    { name: 'b', kind: 'write', transitions: [{ to: 'c', conditional: false, event: null }] },
    { name: 'c', kind: 'automatic', transitions: [] },
  ],
};

const branched: DefinitionStructure = {
  startStep: 'laden',
  steps: [
    {
      name: 'laden',
      kind: 'check',
      transitions: [
        { to: 'pruefen', conditional: true, event: null },
        { to: 'warten', conditional: false, event: null },
      ],
    },
    {
      name: 'pruefen',
      kind: 'interactive',
      transitions: [{ to: 'speichern', conditional: false, event: 'submit' }],
    },
    {
      name: 'warten',
      kind: 'timer',
      transitions: [{ to: 'speichern', conditional: false, event: null }],
    },
    {
      name: 'speichern',
      kind: 'write',
      transitions: [{ to: 'ende', conditional: false, event: null }],
    },
    { name: 'ende', kind: 'workflow', transitions: [] },
  ],
};

describe('thumbnailLayout (E-63)', () => {
  it('puts a straight flow in one row, 18 px per step', () => {
    const layout = thumbnailLayout(linear);
    expect(layout.squares).toEqual([
      { x: 4, y: 16, kind: 'interactive' },
      { x: 22, y: 16, kind: 'data-write' },
      { x: 40, y: 16, kind: 'automatic' },
    ]);
    expect(layout.paths).toEqual(['M12 20H22', 'M30 20H40']);
    expect(layout).toMatchObject({ steps: 3, branches: 0, width: 108 });
  });

  it('draws a fork with lanes at y 10/30 and merges them again', () => {
    const layout = thumbnailLayout(branched);
    expect(layout.squares.map((s) => [s.kind, s.y])).toEqual([
      ['data-check', 16],
      ['interactive', 6],
      ['timer', 26],
      ['data-write', 16],
      ['workflow', 16],
    ]);
    expect(layout.paths[0]).toBe('M12 20C20 20 20 10 28 10');
    expect(layout.paths).toContain('M12 20C20 20 20 30 28 30');
    expect(layout).toMatchObject({ steps: 5, branches: 1 });
  });

  it('grows the canvas for long flows instead of cutting them', () => {
    const steps = Array.from({ length: 9 }, (_, i) => ({
      name: `s${i}`,
      kind: 'automatic',
      transitions: i < 8 ? [{ to: `s${i + 1}`, conditional: false, event: null }] : [],
    }));
    const layout = thumbnailLayout({ startStep: 's0', steps });
    expect(layout.squares).toHaveLength(9);
    expect(layout.width).toBe(4 + 9 * 18 - 10 + 4);
  });

  it('copes with an empty or missing structure', () => {
    expect(thumbnailLayout(null)).toMatchObject({ squares: [], steps: 0, branches: 0 });
  });
});
