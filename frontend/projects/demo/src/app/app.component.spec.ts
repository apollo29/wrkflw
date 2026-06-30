import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { WORKFLOW_API_BASE_URL } from 'workflow-client';
import { AppComponent } from './app.component';

describe('AppComponent', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AppComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: '' },
      ],
    }).compileComponents();
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(AppComponent);
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should render tabs and the runner start button by default', () => {
    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();
    const compiled = fixture.nativeElement as HTMLElement;

    expect(compiled.querySelector('h1')?.textContent).toContain('Workflow-Demo');

    const buttonTexts = Array.from(compiled.querySelectorAll('button')).map((b) => b.textContent ?? '');
    expect(buttonTexts.some((t) => t.includes('Runner'))).toBe(true);
    expect(buttonTexts.some((t) => t.includes('Onboarding starten'))).toBe(true);
  });
});
