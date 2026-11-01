import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { provideWorkflowAdmin } from '../config/config';
import { WfThemeService } from '../ui/theme';
import { workflowAdminRoutes } from './routes';

describe('WfAdminShell', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter(
          [{ path: 'admin', children: workflowAdminRoutes }],
          withComponentInputBinding(),
        ),
        provideWorkflowAdmin({ apiBaseUrl: '/api', theme: 'dunkel' }),
      ],
    });
  });

  it('shows four tabs and marks the current one', async () => {
    const harness = await RouterTestingHarness.create('/admin/instanzen');
    const el =
      harness.routeNativeElement?.closest('wf-admin-shell') ??
      (harness.fixture.nativeElement as HTMLElement);
    const tabs = [...el.querySelectorAll<HTMLAnchorElement>('nav a')];

    expect(tabs.map((t) => t.textContent?.trim())).toEqual([
      'Workflows',
      'Instanzen',
      'Vorlagen',
      'Datenquellen',
    ]);
    expect(tabs.map((t) => t.getAttribute('href'))).toEqual([
      '/admin',
      '/admin/instanzen',
      '/admin/vorlagen',
      '/admin/datenquellen',
    ]);
    expect(tabs[1]?.getAttribute('aria-current')).toBe('page');
    expect(tabs[0]?.getAttribute('aria-current')).toBeNull();
    expect(el.querySelector('h2')?.textContent).toContain('Instanzen');
    expect(el.textContent).toContain('Dieser Bereich folgt in Phase 8.');
  });

  it('sets the theme on the root and follows changes', async () => {
    const harness = await RouterTestingHarness.create('/admin');
    const shell = (harness.fixture.nativeElement as HTMLElement).querySelector(
      'wf-admin-shell',
    ) as HTMLElement;
    expect(shell.classList).toContain('wf');
    expect(shell.getAttribute('data-theme')).toBe('dunkel');

    TestBed.inject(WfThemeService).set('hell');
    harness.detectChanges();
    expect(shell.getAttribute('data-theme')).toBe('hell');
  });

  it('passes route parameters to the editor page', async () => {
    const harness = await RouterTestingHarness.create('/admin/workflows/antrag/v/2');
    expect(harness.routeNativeElement?.textContent).toContain('Editor: antrag');
    expect(harness.routeNativeElement?.textContent).toContain('Version 2');
    expect(TestBed.inject(Router).url).toBe('/admin/workflows/antrag/v/2');
  });
});
