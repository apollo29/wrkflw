import { EditorModel, PreviewLane, previewFlow } from './definition-mapping';

export type LaneLabel =
  | { readonly kind: 'condition'; readonly text: string }
  | { readonly kind: 'button'; readonly text: string }
  | { readonly kind: 'else'; readonly text: '' };

/** Beschriftung einer Spur: Bedingung, Knopf (9.14) oder «SONST». */
export function laneLabel(lane: PreviewLane): LaneLabel {
  if (!lane.isElse) {
    return { kind: 'condition', text: lane.condition };
  }
  return lane.button !== null ? { kind: 'button', text: lane.button } : { kind: 'else', text: '' };
}

export interface FlowLane {
  readonly label: LaneLabel;
  readonly steps: readonly string[];
}

/** Abschnitte der Ablauf-Vorschau, wie sie gezeichnet werden (E-11 bis E-18). */
export type FlowSection =
  | { readonly kind: 'chain'; readonly steps: readonly string[] }
  | {
      readonly kind: 'fork';
      /** Der Schritt davor, links im Bild der Verzweigung (null, wenn der Ablauf hier beginnt). */
      readonly prev: string | null;
      readonly from: string;
      readonly lanes: readonly FlowLane[];
      readonly merge: string | null;
      /** Einspurig nach der Zusammenführung, auf derselben Zeile (E-15). */
      readonly tail: readonly string[];
      /** Direkt danach folgt die nächste Verzweigung: Zeile endet mit «…». */
      readonly continues: boolean;
    }
  | { readonly kind: 'unreachable'; readonly steps: readonly string[] };

function reachable(model: EditorModel): Set<string> {
  const byName = new Map(model.steps.map((s) => [s.name, s]));
  const seen = new Set<string>();
  const queue = byName.has(model.startStep) ? [model.startStep] : [];
  while (queue.length > 0) {
    const name = queue.shift() as string;
    if (seen.has(name)) {
      continue;
    }
    seen.add(name);
    for (const t of byName.get(name)?.transitions ?? []) {
      if (byName.has(t.to)) {
        queue.push(t.to);
      }
    }
  }
  return seen;
}

export function flowSections(model: EditorModel): FlowSection[] {
  const nodes = previewFlow(model);
  const reach = reachable(model);
  const sections: FlowSection[] = [];
  const unreachable: string[] = [];
  let chain: string[] = [];
  let lastFork: { tail: string[]; index: number } | null = null;

  const flushChain = (): void => {
    if (chain.length > 0) {
      sections.push({ kind: 'chain', steps: chain });
      chain = [];
    }
  };

  for (const node of nodes) {
    if (node.kind === 'step') {
      if (!reach.has(node.name)) {
        unreachable.push(node.name);
      } else if (lastFork) {
        lastFork.tail.push(node.name);
      } else {
        chain.push(node.name);
      }
      continue;
    }
    if (lastFork) {
      const previous = sections[lastFork.index];
      if (previous?.kind === 'fork') {
        sections[lastFork.index] = {
          ...previous,
          tail: lastFork.tail,
          continues: lastFork.tail.length === 0,
        };
      }
    }
    const prev = lastFork ? (lastFork.tail.at(-1) ?? null) : (chain.pop() ?? null);
    if (lastFork && lastFork.tail.length > 0) {
      // Der letzte Schritt der Zeile wird zum Zusammenhang der nächsten Verzweigung.
      const previous = sections[lastFork.index];
      if (previous?.kind === 'fork') {
        sections[lastFork.index] = {
          ...previous,
          tail: lastFork.tail.slice(0, -1),
          continues: true,
        };
      }
    }
    flushChain();
    sections.push({
      kind: 'fork',
      prev,
      from: node.from,
      lanes: node.lanes.map((lane) => ({ label: laneLabel(lane), steps: lane.steps })),
      merge: node.merge,
      tail: [],
      continues: false,
    });
    lastFork = { tail: [], index: sections.length - 1 };
  }

  if (lastFork) {
    const previous = sections[lastFork.index];
    if (previous?.kind === 'fork') {
      sections[lastFork.index] = { ...previous, tail: lastFork.tail };
    }
  }
  flushChain();
  if (unreachable.length > 0) {
    sections.push({ kind: 'unreachable', steps: unreachable });
  }
  return sections;
}
