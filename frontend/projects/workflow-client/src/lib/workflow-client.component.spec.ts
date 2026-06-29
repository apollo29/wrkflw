import { ComponentFixture, TestBed } from '@angular/core/testing';

import { WorkflowClientComponent } from './workflow-client.component';

describe('WorkflowClientComponent', () => {
  let component: WorkflowClientComponent;
  let fixture: ComponentFixture<WorkflowClientComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [WorkflowClientComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(WorkflowClientComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
