import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import type { DefinitionSummary } from '../api/models';
import { provideWorkflowAdmin } from '../config/config';
import { WfDialogService } from '../ui/dialog';
import { WfWorkflowOverview } from './workflow-overview';

const DEFS: DefinitionSummary[] = [
  {
    id: 'antrag-pruefung',
    name: 'Antrag prüfen',
    version: 3,
    status: 'active',
    updatedAt: '2026-10-28T09:00:00Z',
    runningInstances: 2,
    totalInstances: 12,
    olderVersions: 2,
    olderRunning: [{ version: 2, running: 1 }],
    structure: { startStep: 'a', steps: [{ name: 'a', kind: 'interactive', transitions: [] }] },
  },
  {
    id: 'mahnung',
    name: 'Mahnung',
    version: 1,
    status: 'draft',
    updatedAt: '2026-10-20T09:00:00Z',
    runningInstances: 0,
    totalInstances: 0,
    olderVersions: 0,
    structure: null,
  },
];

describe('WfWorkflowOverview', () => {
  let http: HttpTestingController;
  let confirm: ReturnType<typeof vi.fn>;

  async function setup(defs: DefinitionSummary[] = DEFS) {
    confirm = vi.fn().mockResolvedValue(true);
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideWorkflowAdmin({ apiBaseUrl: '/api' }),
        provideRouter([
          { path: '', component: WfWorkflowOverview },
          { path: 'workflows/:id', children: [] },
        ]),
        { provide: WfDialogService, useValue: { confirm } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const harness = await RouterTestingHarness.create('/');
    http.expectOne('/api/workflows').flush({ definitions: defs });
    await harness.fixture.whenStable();
    return { harness, el: harness.routeNativeElement as HTMLElement };
  }

  it('shows one row per workflow with runs, older running and status (E-60 to E-62, E-66)', async () => {
    const { el } = await setup();
    const rows = el.querySelectorAll('[data-workflow]');
    expect(rows).toHaveLength(2);
    expect(rows[0]?.textContent).toContain('2 laufend · 12 gesamt');
    expect(rows[0]?.textContent).toContain('1 laufend auf v2');
    expect(rows[0]?.textContent).toContain('28.10.2026');
    expect(rows[1]?.textContent).toContain('keine Durchläufe');
    expect(rows[1]?.querySelector('[data-testid="overview-older"]')).toBeNull();
    expect(el.querySelector('svg[role="img"]')?.getAttribute('aria-label')).toBe(
      '1 Schritte, keine Verzweigung',
    );
  });

  it('filters by text and status and explains an empty result (E-61, E-67)', async () => {
    const { harness, el } = await setup();
    const search = el.querySelector<HTMLInputElement>('[data-testid="overview-search"]')!;
    search.value = 'MAHN';
    search.dispatchEvent(new Event('input'));
    await harness.fixture.whenStable();
    expect(
      [...el.querySelectorAll('[data-workflow]')].map((r) => r.getAttribute('data-workflow')),
    ).toEqual(['mahnung']);

    search.value = 'gibt-es-nicht';
    search.dispatchEvent(new Event('input'));
    await harness.fixture.whenStable();
    expect(el.textContent).toContain('Keine Workflows gefunden.');
  });

  it('shows the empty state without workflows', async () => {
    const { el } = await setup([]);
    expect(el.textContent).toContain('Noch keine Workflows.');
  });

  it('opens the editor on a row click (E-64)', async () => {
    const { harness, el } = await setup();
    (el.querySelector('[data-workflow="mahnung"] .wf-ov-row') as HTMLElement).click();
    await harness.fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/workflows/mahnung');
  });

  it('lists older versions, removes one after confirmation and locks referenced ones (E-65, E-66)', async () => {
    const { harness, el } = await setup();
    (el.querySelector('[data-testid="overview-older"]') as HTMLButtonElement).click();
    const versions = (deletable: boolean) => ({
      id: 'antrag-pruefung',
      versions: [
        {
          version: 3,
          name: 'x',
          status: 'active',
          createdAt: '',
          updatedAt: '',
          runningInstances: 2,
          totalInstances: 8,
          deletable: false,
          deleteBlockedBy: 'latest',
        },
        ...(deletable
          ? [
              {
                version: 2,
                name: 'x',
                status: 'inactive',
                createdAt: '',
                updatedAt: '2026-10-03T00:00:00Z',
                runningInstances: 0,
                totalInstances: 0,
                deletable: true,
                deleteBlockedBy: null,
              },
            ]
          : []),
        {
          version: 1,
          name: 'x',
          status: 'inactive',
          createdAt: '',
          updatedAt: '',
          runningInstances: 0,
          totalInstances: 4,
          deletable: false,
          deleteBlockedBy: 'referenced',
        },
      ],
    });
    http.expectOne('/api/workflows/antrag-pruefung/versions').flush(versions(true));
    await harness.fixture.whenStable();

    expect(
      [...el.querySelectorAll('[data-version]')].map((r) => r.getAttribute('data-version')),
    ).toEqual(['2', '1']);
    const locked = el.querySelector<HTMLButtonElement>('[data-version="1"] button')!;
    expect(locked.disabled).toBe(true);
    expect(locked.getAttribute('aria-label')).toBe('Version v1 entfernen – gesperrt');
    expect(el.querySelector('[data-version="1"]')?.textContent).toContain(
      'Ein abgeschlossener Durchlauf verweist noch darauf.',
    );
    expect(el.querySelector('[data-version="2"] a')?.getAttribute('href')).toBe(
      '/workflows/antrag-pruefung/v/2',
    );

    el.querySelector<HTMLButtonElement>('[data-version="2"] button')!.click();
    await harness.fixture.whenStable();
    expect(confirm).toHaveBeenCalled();
    http
      .expectOne(
        (r) => r.method === 'DELETE' && r.url === '/api/workflows/antrag-pruefung/versions/2',
      )
      .flush(null, { status: 204, statusText: 'No Content' });
    await harness.fixture.whenStable();
    http.expectOne('/api/workflows/antrag-pruefung/versions').flush(versions(false));
    await new Promise((resolve) => setTimeout(resolve));
    TestBed.tick();
    http.expectOne((r) => r.url === '/api/workflows').flush({ definitions: DEFS });
    await harness.fixture.whenStable();
    expect(el.querySelector('[data-testid="overview-message"]')?.textContent).toContain(
      'antrag-pruefung v2 entfernt.',
    );
  });
});
