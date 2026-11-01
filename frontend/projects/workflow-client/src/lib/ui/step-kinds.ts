import type { WfMessageKey } from '../i18n/messages.de';
import type { WfIconName } from './icon';

/** Die sechs Schritt-Arten des Editors (9.4). */
export const STEP_KINDS = [
  'automatic',
  'interactive',
  'timer',
  'workflow',
  'data-check',
  'data-write',
] as const;
export type StepKind = (typeof STEP_KINDS)[number];

export interface StepKindInfo {
  readonly kind: StepKind;
  readonly icon: WfIconName;
  /** CSS-Klasse mit den Farben der Art (`tokens.css`). */
  readonly colorClass: string;
  readonly label: WfMessageKey;
  readonly description: WfMessageKey;
}

export function stepKindInfo(kind: StepKind): StepKindInfo {
  return {
    kind,
    icon: kind,
    colorClass: `wf-k-${kind}`,
    label: `step.kind.${kind}`,
    description: `step.kind.${kind}.description`,
  };
}

/**
 * Art eines gespeicherten Schritts: Workflow, Datencheck und Daten schreiben sind
 * automatische Schritte mit einer bestimmten Aktion.
 */
export function stepKindOf(step: {
  readonly type?: string;
  readonly action?: string | null;
}): StepKind {
  switch (step.type) {
    case 'interactive':
      return 'interactive';
    case 'timer':
      return 'timer';
  }
  switch (step.action) {
    case 'start_workflow':
      return 'workflow';
    case 'check_data':
      return 'data-check';
    case 'write_data':
      return 'data-write';
    default:
      return 'automatic';
  }
}
