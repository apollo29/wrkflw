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

  it('inserts a placeholder token into the value', () => {
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

  it('toggles the source view', () => {
    expect(component.showSource()).toBe(false);
    component.toggleSource();
    expect(component.showSource()).toBe(true);
  });
});
