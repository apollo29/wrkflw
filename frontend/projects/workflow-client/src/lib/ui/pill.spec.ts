import { definitionStatusPill, instanceStatusPill } from './pill';
import { stepKindInfo, stepKindOf } from './step-kinds';

describe('status and step kinds', () => {
  it('maps instance and definition status to colours and texts', () => {
    expect(instanceStatusPill('failed')).toEqual({
      kind: 'error',
      label: 'status.instance.failed',
    });
    expect(instanceStatusPill('cancelled').kind).toBe('neutral');
    expect(definitionStatusPill('active').kind).toBe('ok');
    expect(definitionStatusPill('draft').dashed).toBe(true);
  });

  it('derives the six step kinds from type and action', () => {
    expect(stepKindOf({ type: 'interactive' })).toBe('interactive');
    expect(stepKindOf({ type: 'timer' })).toBe('timer');
    expect(stepKindOf({ type: 'automatic', action: 'start_workflow' })).toBe('workflow');
    expect(stepKindOf({ action: 'check_data' })).toBe('data-check');
    expect(stepKindOf({ action: 'write_data' })).toBe('data-write');
    expect(stepKindOf({ action: 'send_email' })).toBe('automatic');
    expect(stepKindInfo('data-write')).toMatchObject({
      icon: 'data-write',
      colorClass: 'wf-k-data-write',
      label: 'step.kind.data-write',
    });
  });
});
