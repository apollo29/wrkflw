import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { WORKFLOW_API_BASE_URL } from '@apollo29/workflow-client';
import { AppComponent } from './app.component';

describe('AppComponent', () => {
  let httpMock: HttpTestingController;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [AppComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: '' },
      ],
    }).compileComponents();
    httpMock = TestBed.inject(HttpTestingController);
  });

  it('should create the app', () => {
    const fixture = TestBed.createComponent(AppComponent);
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('should render tabs and a workflow starter that lists definitions', () => {
    const fixture = TestBed.createComponent(AppComponent);
    fixture.detectChanges();

    // Runner-Tab lädt die Definitionsliste beim Init.
    httpMock
      .expectOne('/workflows')
      .flush({ definitions: [{ id: 'onboarding', version: 1, name: 'Onboarding', active: true }] });
    fixture.detectChanges();

    const compiled = fixture.nativeElement as HTMLElement;
    expect(compiled.querySelector('h1')?.textContent).toContain('Workflow-Demo');

    const buttonTexts = Array.from(compiled.querySelectorAll('button')).map((b) => b.textContent ?? '');
    expect(buttonTexts.some((t) => t.includes('Runner'))).toBe(true);
    expect(buttonTexts.some((t) => t.includes('Workflow starten'))).toBe(true);

    // Vorauswahl bevorzugt 'onboarding'.
    expect(fixture.componentInstance.selectedDef()).toBe('onboarding');
  });
});
