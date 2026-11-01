import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import type { DefinitionStatus, InstanceStatus } from '../api/models';
import type { WfMessageKey } from '../i18n/messages.de';
import { WfIcon, WfIconName } from './icon';

export type WfPillKind =
  | 'automatic'
  | 'interactive'
  | 'timer'
  | 'workflow'
  | 'data-check'
  | 'data-write'
  | 'ok'
  | 'error'
  | 'neutral'
  | 'plain';

export interface WfPillSpec {
  readonly kind: WfPillKind;
  readonly label: WfMessageKey;
  readonly dashed?: boolean;
}

/** Farbe und Text je Status eines Durchlaufs (Reiter «Instanzen»). */
export function instanceStatusPill(status: InstanceStatus): WfPillSpec {
  const kind: Record<InstanceStatus, WfPillKind> = {
    running: 'automatic',
    waiting_event: 'interactive',
    waiting_timer: 'timer',
    completed: 'ok',
    failed: 'error',
    cancelled: 'neutral',
  };
  return { kind: kind[status], label: `status.instance.${status}` };
}

/** Farbe und Text je Status einer Definition (Übersicht). */
export function definitionStatusPill(status: DefinitionStatus): WfPillSpec {
  switch (status) {
    case 'active':
      return { kind: 'ok', label: 'status.definition.active' };
    case 'draft':
      return { kind: 'plain', label: 'status.definition.draft', dashed: true };
    case 'inactive':
      return { kind: 'plain', label: 'status.definition.inactive' };
  }
}

/** Kleine Marke für Status, Art oder Version. Der Text kommt als Inhalt. */
@Component({
  selector: 'wf-pill',
  imports: [WfIcon],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '[class]': '"wf-k-" + kind()',
    '[class.wf-pill--color]': 'kind() !== "plain"',
    '[class.wf-pill--dashed]': 'dashed()',
    '[class.wf-mono]': 'mono()',
  },
  template: `
    @if (dot()) {
      <span class="wf-pill__dot" aria-hidden="true"></span>
    }
    @if (icon(); as name) {
      <wf-icon [name]="name" [size]="13" />
    }
    <ng-content />
  `,
  styles: `
    :host {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      height: 22px;
      padding: 0 9px;
      border: 1px solid var(--wfb-border-strong);
      border-radius: var(--wfb-radius-full);
      background: var(--wfb-bg);
      color: var(--wfb-text-muted);
      font-size: 11.5px;
      font-weight: 500;
      white-space: nowrap;
      vertical-align: middle;
    }
    :host(.wf-pill--color) {
      background: var(--wfb-k-bg);
      border-color: var(--wfb-k-bd);
      color: var(--wfb-k-fg);
    }
    :host(.wf-pill--dashed) {
      border-style: dashed;
    }
    .wf-pill__dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: currentColor;
      flex: none;
    }
  `,
})
export class WfPill {
  readonly kind = input<WfPillKind>('plain');
  readonly dashed = input(false);
  readonly dot = input(false);
  readonly mono = input(false);
  readonly icon = input<WfIconName | null>(null);
}
