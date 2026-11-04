import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import type { InstanceSummary } from '../api/models';
import { provideWorkflowAdmin } from '../config/config';
import { WfDialogService } from '../ui/dialog';
import { INSTANCE_PAGE_SIZE, WfInstanceList } from './instance-list';

function instance(
  id: string,
  status: InstanceSummary['status'],
  extra: Partial<InstanceSummary> = {},
): InstanceSummary {
  return {
    id,
    definitionId: 'antrag',
    definitionVersion: 3,
    definitionName: 'Antrag prüfen',
    currentStep: 'pruefen',
    status,
    subjectType: 'antrag',
    subjectId: `A-${id}`,
    wakeAt: null,
    lastError: null,
    createdAt: '2026-10-30T10:00:00Z',
    updatedAt: '2026-10-31T08:12:00Z',
    ...extra,
  };
}

const COUNTS = {
  running: 0,
  waiting_event: 1,
  waiting_timer: 0,
  completed: 1,
  failed: 1,
  cancelled: 0,
};

describe('WfInstanceList', () => {
  let http: HttpTestingController;
  let confirm: ReturnType<typeof vi.fn>;

  async function setup(url = '/') {
    confirm = vi.fn().mockResolvedValue(true);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideWorkflowAdmin({ apiBaseUrl: '/api' }),
        provideRouter([{ path: '', component: WfInstanceList }]),
        { provide: WfDialogService, useValue: { confirm } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const harness = await RouterTestingHarness.create(url);
    http.expectOne('/api/workflows').flush({
      definitions: [
        {
          id: 'antrag',
          structure: {
            startStep: 'pruefen',
            steps: [{ name: 'pruefen', kind: 'interactive', transitions: [] }],
          },
        },
      ],
    });
    return { harness, el: harness.routeNativeElement as HTMLElement };
  }

  function answerList(
    instances: InstanceSummary[],
    match: (params: URLSearchParams) => boolean = () => true,
  ): void {
    http
      .expectOne(
        (r) => r.url === '/api/instances' && match(new URLSearchParams(r.params.toString())),
      )
      .flush({ instances, counts: COUNTS });
  }

  it('shows status, error reason, step colour and counts (F-92)', async () => {
    const { harness, el } = await setup();
    answerList([
      instance('1', 'waiting_event'),
      instance('2', 'failed', { lastError: 'Mailversand: Zeitüberschreitung.' }),
    ]);
    await harness.fixture.whenStable();
    expect(el.querySelectorAll('tbody tr[data-instance]')).toHaveLength(2);
    expect(el.querySelector('[data-testid="instance-error"]')?.textContent).toContain(
      'Mailversand: Zeitüberschreitung.',
    );
    expect(el.querySelector('.wf-in-sq')?.classList).toContain('wf-k-interactive');
    expect(el.textContent).toContain('3 Durchläufe, davon 1 laufend.');
    const edit = [...el.querySelectorAll('[data-testid="instance-edit"]')];
    expect(edit).toHaveLength(1);
    expect(edit[0]?.closest('tr')?.getAttribute('data-instance')).toBe('1');
    expect(edit[0]?.getAttribute('href')).toBe('/1');
  });

  it('keeps the status filter in the address', async () => {
    const { harness, el } = await setup('/?status=failed');
    answerList([], (p) => p.get('status') === 'failed');
    await harness.fixture.whenStable();
    expect(el.textContent).toContain('Keine Durchläufe mit diesem Status.');

    [...el.querySelectorAll<HTMLButtonElement>('[role="group"] button')]
      .find((b) => b.textContent?.includes('Alle'))!
      .click();
    await harness.fixture.whenStable();
    answerList([], (p) => !p.has('status'));
    expect(TestBed.inject(Router).url).toBe('/');
  });

  it('opens the history with names only and cancels after confirmation', async () => {
    const { harness, el } = await setup();
    answerList([instance('1', 'waiting_event')]);
    await harness.fixture.whenStable();
    el.querySelector<HTMLButtonElement>('[data-testid="instance-history"]')!.click();
    http.expectOne('/api/instances/1/history').flush({
      instanceId: '1',
      history: [
        {
          kind: 'start',
          step: 'a',
          detail: { contextKeys: ['antrag_id'] },
          at: '2026-10-30T16:31:00Z',
        },
        {
          kind: 'event',
          step: 'pruefen',
          detail: { event: 'submit', payloadKeys: ['bemerkung'] },
          at: '2026-10-30T16:40:00Z',
        },
      ],
    });
    await harness.fixture.whenStable();
    const items = [...el.querySelectorAll('.wf-in-hist li')].map((li) => li.textContent);
    expect(items[0]).toContain('Gestartet · Startkontext: antrag_id');
    expect(items[1]).toContain('Ereignis submit in pruefen · Felder: bemerkung');

    el.querySelector<HTMLButtonElement>('[data-testid="instance-cancel"]')!.click();
    await harness.fixture.whenStable();
    expect(confirm).toHaveBeenCalled();
    http
      .expectOne((r) => r.method === 'POST' && r.url === '/api/instances/1/cancel')
      .flush({ id: '1', status: 'cancelled', currentStep: 'pruefen' });
    await harness.fixture.whenStable();
    answerList([instance('1', 'cancelled')]);
    await harness.fixture.whenStable();
    expect(el.querySelector('[data-testid="instance-cancel"]')).toBeNull();
  });

  it('loads more when a full page came back', async () => {
    const { harness, el } = await setup();
    answerList(
      Array.from({ length: INSTANCE_PAGE_SIZE }, (_, i) => instance(String(i), 'completed')),
    );
    await harness.fixture.whenStable();
    el.querySelector<HTMLButtonElement>('[data-testid="instances-more"]')!.click();
    answerList([instance('x', 'completed')], (p) => p.get('offset') === String(INSTANCE_PAGE_SIZE));
    await harness.fixture.whenStable();
    expect(el.querySelectorAll('tbody tr[data-instance]')).toHaveLength(INSTANCE_PAGE_SIZE + 1);
    expect(el.querySelector('[data-testid="instances-more"]')).toBeNull();
  });
});
