import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { CLIENT_VERSION } from '@apollo29/workflow-client';
import { App } from './app';

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [provideRouter([])],
    }).compileComponents();
  });

  it('shows the client version from the library', async () => {
    const fixture = TestBed.createComponent(App);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('h1')?.textContent).toContain('wrkflw Demo');
    expect(el.querySelector('[data-testid="client-version"]')?.textContent).toBe(CLIENT_VERSION);
  });
});
