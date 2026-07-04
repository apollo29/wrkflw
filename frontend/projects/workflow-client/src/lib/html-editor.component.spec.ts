import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HtmlEditorComponent } from './html-editor.component';

describe('HtmlEditorComponent', () => {
  let fixture: ComponentFixture<HtmlEditorComponent>;
  let component: HtmlEditorComponent;

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [HtmlEditorComponent] });
    fixture = TestBed.createComponent(HtmlEditorComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('inserts a placeholder token into the value (editor mode)', () => {
    component.insertPlaceholder('name');
    expect(component.value()).toContain('{{name}}');
  });

  it('appends a placeholder in source mode', () => {
    component.toggleSource();
    fixture.detectChanges();
    component.value.set('<p>Hallo</p>');
    component.insertPlaceholder('email');
    expect(component.value()).toBe('<p>Hallo</p>{{email}}');
  });

  it('onPick inserts and clears the selection', () => {
    component.onPick('plan');
    expect(component.value()).toContain('{{plan}}');
    expect(component.pick()).toBe('');
  });

  it('ignores blank placeholder names', () => {
    component.insertPlaceholder('   ');
    expect(component.value()).toBe('');
  });

  it('toggles source and preview views', () => {
    expect(component.showSource()).toBe(false);
    component.toggleSource();
    expect(component.showSource()).toBe(true);

    expect(component.showPreview()).toBe(false);
    component.togglePreview();
    expect(component.showPreview()).toBe(true);
  });

  it('renders a preview with resolved sample values', () => {
    component.toggleSource();
    fixture.detectChanges();
    component.value.set('<p>Hallo {{name}}, {{email}}</p>');
    component.togglePreview();
    fixture.detectChanges();

    const body = fixture.nativeElement.querySelector('.wfb-html__preview-body') as HTMLElement;
    expect(body).toBeTruthy();
    expect(body.textContent).toContain('Max Mustermann');
    expect(body.textContent).toContain('max@example.com');
  });
});
