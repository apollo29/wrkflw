import { EnvironmentInjector, runInInjectionContext } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ActivatedRouteSnapshot, RouterStateSnapshot } from '@angular/router';
import { WfDialogService } from '../ui/dialog';
import { WfToastService } from '../ui/toast';
import { WfUnsavedChanges, wfUnsavedChangesGuard } from './unsaved-changes';

function page(dirty: boolean, save?: () => Promise<boolean>): WfUnsavedChanges {
  return { hasUnsavedChanges: () => dirty, ...(save ? { save } : {}) };
}

async function run(component: WfUnsavedChanges): Promise<unknown> {
  const route = {} as ActivatedRouteSnapshot;
  const state = {} as RouterStateSnapshot;
  return runInInjectionContext(TestBed.inject(EnvironmentInjector), () =>
    wfUnsavedChangesGuard(component, route, state, state),
  );
}

describe('wfUnsavedChangesGuard', () => {
  let choice: string;
  let offered: string[];

  beforeEach(() => {
    offered = [];
    TestBed.configureTestingModule({
      providers: [
        {
          provide: WfDialogService,
          useValue: {
            confirm: (options: { actions: { value: string; label: string }[]; title: string }) => {
              offered = options.actions.map((a) => a.label);
              expect(options.title).toBe('Ungespeicherte Änderungen');
              return Promise.resolve(choice);
            },
          },
        },
      ],
    });
  });

  it('lets clean pages go without asking', async () => {
    expect(await run(page(false))).toBe(true);
    expect(offered).toEqual([]);
  });

  it('discards or stays', async () => {
    choice = 'discard';
    expect(await run(page(true))).toBe(true);
    expect(offered).toEqual(['Verwerfen', 'Im Editor bleiben']);
    choice = 'stay';
    expect(await run(page(true))).toBe(false);
  });

  it('saves and leaves, or stays with a message if saving fails', async () => {
    choice = 'save';
    expect(await run(page(true, () => Promise.resolve(true)))).toBe(true);
    expect(offered).toEqual(['Verwerfen', 'Im Editor bleiben', 'Speichern und verlassen']);

    expect(await run(page(true, () => Promise.reject(new Error('kaputt'))))).toBe(false);
    expect(
      TestBed.inject(WfToastService)
        .toasts()
        .map((t) => t.message),
    ).toEqual(['Speichern hat nicht geklappt. Die Änderungen sind noch da.']);
  });
});
