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

  it('inserts an email button, divider, columns and image', () => {
    component.insertButton('Jetzt kaufen', 'https://shop.example.com');
    expect(component.value()).toContain('data-email-button');
    expect(component.value()).toContain('Jetzt kaufen');

    component.insertDivider();
    expect(component.value()).toContain('<hr');

    component.insertColumns();
    expect(component.value()).toContain('data-columns');
    expect(component.value()).toContain('data-column');

    component.insertImage('https://img.example.com/logo.png');
    expect(component.value()).toContain('<img');
    expect(component.value()).toContain('logo.png');
  });

  it('keeps placeholders as plain text in the value (chips are display-only)', () => {
    component.insertPlaceholder('name');
    // getHTML enthält den reinen Platzhalter, kein Chip-Markup.
    expect(component.value()).toContain('{{name}}');
    expect(component.value()).not.toContain('wfb-ph');
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
