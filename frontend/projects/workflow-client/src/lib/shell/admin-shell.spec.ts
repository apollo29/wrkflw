import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
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
        provideHttpClient(),
        provideHttpClientTesting(),
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

  it('opens the editor for a new workflow', async () => {
    const harness = await RouterTestingHarness.create('/admin/workflows/neu');
    const editor = (harness.fixture.nativeElement as HTMLElement).querySelector(
      'wf-workflow-editor',
    );
    expect(editor).not.toBeNull();
    expect(editor?.querySelector('[data-testid="editor-version"]')?.textContent).toContain('neu');
    expect(TestBed.inject(Router).url).toBe('/admin/workflows/neu');
  });
});
