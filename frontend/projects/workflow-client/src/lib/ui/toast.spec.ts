import { TestBed } from '@angular/core/testing';
import { WfToastService } from './toast';

describe('WfToastService', () => {
  beforeEach(() => vi.useFakeTimers());
  afterEach(() => vi.useRealTimers());

  it('disappears after the duration and runs the undo action once', () => {
    const toasts = TestBed.inject(WfToastService);
    const undo = vi.fn();
    toasts.show({ message: 'Gelöscht', actionLabel: 'Rückgängig', onAction: undo });
    const second = toasts.show({ message: 'Zweite', durationMs: 1000 });
    expect(toasts.toasts().map((t) => t.message)).toEqual(['Gelöscht', 'Zweite']);

    vi.advanceTimersByTime(1000);
    expect(toasts.toasts().map((t) => t.message)).toEqual(['Gelöscht']);
    second.dismiss();

    const id = toasts.toasts()[0]?.id ?? -1;
    toasts.act(id);
    toasts.act(id);
    expect(undo).toHaveBeenCalledTimes(1);
    expect(toasts.toasts()).toEqual([]);
  });

  it('pauses while focused or hovered', () => {
    const toasts = TestBed.inject(WfToastService);
    toasts.show({ message: 'Gelöscht', durationMs: 6000 });
    const id = toasts.toasts()[0]?.id ?? -1;

    vi.advanceTimersByTime(4000);
    toasts.pause(id);
    vi.advanceTimersByTime(10_000);
    expect(toasts.toasts()).toHaveLength(1);

    toasts.resume(id);
    vi.advanceTimersByTime(1999);
    expect(toasts.toasts()).toHaveLength(1);
    vi.advanceTimersByTime(1);
    expect(toasts.toasts()).toHaveLength(0);
  });
});
