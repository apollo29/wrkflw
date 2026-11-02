import type { DefinitionStructure } from '../api/models';
import { EditorModel, EditorStep, emptyStep } from '../editor/model/definition-mapping';
import { flowSections } from '../editor/model/flow';
import type { StepKind } from '../ui/step-kinds';

export interface ThumbnailSquare {
  readonly x: number;
  readonly y: number;
  readonly kind: StepKind;
}

export interface ThumbnailLayout {
  readonly width: number;
  readonly squares: readonly ThumbnailSquare[];
  readonly paths: readonly string[];
  readonly steps: number;
  readonly branches: number;
}

const SIZE = 8;
const STEP = 18;
const MID = 20;
const START = 4;
const MIN_WIDTH = 108;

const KIND: Record<string, StepKind> = {
  interactive: 'interactive',
  timer: 'timer',
  automatic: 'automatic',
  workflow: 'workflow',
  check: 'data-check',
  write: 'data-write',
};

/** Mini-Modell aus der Struktur von `GET /workflows`, damit dieselbe Berechnung gilt wie im Editor. */
function modelOf(structure: DefinitionStructure): EditorModel {
  return {
    id: '',
    name: '',
    startStep: structure.startStep,
    inputs: [],
    steps: structure.steps.map((s): EditorStep => ({
      ...emptyStep(s.name, 'automatic'),
      transitions: s.transitions.map((t) => ({
        to: t.to,
        event: t.event,
        mode: 'raw',
        condition: { field: '', op: '==', value: '' },
        raw: t.conditional ? 'bedingt' : 'true',
        label: '',
      })),
    })),
  };
}

function laneYs(count: number): number[] {
  if (count <= 1) {
    return [MID];
  }
  if (count === 2) {
    return [10, 30];
  }
  return Array.from({ length: count }, (_, i) => 6 + (i * 28) / (count - 1));
}

/**
 * Vorschaubild der Übersicht (E-63): je Schritt ein Quadrat in der Farbe der Art, Linien
 * dazwischen, Verzweigungen als Spuren. Gleiche Abschnitte wie die Ablauf-Vorschau (9.5);
 * lange Abläufe werden breiter und beim Zeichnen verkleinert, nicht abgeschnitten.
 */
export function thumbnailLayout(structure: DefinitionStructure | null): ThumbnailLayout {
  if (!structure || structure.steps.length === 0) {
    return { width: MIN_WIDTH, squares: [], paths: [], steps: 0, branches: 0 };
  }
  const kinds = new Map(structure.steps.map((s) => [s.name, KIND[s.kind] ?? 'automatic']));
  const squares: ThumbnailSquare[] = [];
  const paths: string[] = [];
  let x = START;
  let lastRight: number | null = null;
  let branches = 0;

  const place = (name: string, y = MID): number => {
    const left = x;
    if (lastRight !== null && y === MID) {
      paths.push(`M${lastRight} ${MID}H${left}`);
    }
    squares.push({ x: left, y: y - SIZE / 2, kind: kinds.get(name) ?? 'automatic' });
    x += STEP;
    lastRight = left + SIZE;
    return left;
  };

  for (const section of flowSections(modelOf(structure))) {
    if (section.kind === 'chain') {
      section.steps.forEach((name) => place(name));
      continue;
    }
    if (section.kind === 'unreachable') {
      lastRight = null;
      x += 6;
      section.steps.forEach((name) => {
        place(name);
        lastRight = null;
      });
      continue;
    }
    branches++;
    if (section.prev) {
      place(section.prev);
    }
    const fromLeft = place(section.from);
    const source = fromLeft + SIZE;
    const ys = laneYs(section.lanes.length);
    const laneStart = source + 16;
    const laneEnds: number[] = [];
    section.lanes.forEach((lane, i) => {
      const y = ys[i] ?? MID;
      paths.push(
        y === MID
          ? `M${source} ${MID}H${laneStart}`
          : `M${source} ${MID}C${source + 8} ${MID} ${source + 8} ${y} ${laneStart} ${y}`,
      );
      let lx = laneStart;
      lane.steps.forEach((name, j) => {
        if (j > 0) {
          paths.push(`M${lx - STEP + SIZE} ${y}H${lx}`);
        }
        squares.push({ x: lx, y: y - SIZE / 2, kind: kinds.get(name) ?? 'automatic' });
        lx += STEP;
      });
      laneEnds.push(lane.steps.length > 0 ? lx - STEP + SIZE : laneStart);
    });
    const end = Math.max(...laneEnds);
    x = end + 16;
    lastRight = null;
    if (section.merge) {
      section.lanes.forEach((_, i) => {
        const y = ys[i] ?? MID;
        const from = laneEnds[i] ?? end;
        const straight = from < end ? `M${from} ${y}H${end}` : '';
        if (straight) {
          paths.push(straight);
        }
        paths.push(
          y === MID
            ? `M${end} ${MID}H${x}`
            : `M${end} ${y}C${end + 8} ${y} ${end + 8} ${MID} ${x} ${MID}`,
        );
      });
      place(section.merge);
      section.tail.forEach((name) => place(name));
    }
  }

  return {
    width: Math.max(MIN_WIDTH, x - STEP + SIZE + START),
    squares,
    paths,
    steps: structure.steps.length,
    branches,
  };
}
