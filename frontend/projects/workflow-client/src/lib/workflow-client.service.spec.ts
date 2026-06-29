import { TestBed } from '@angular/core/testing';

import { WorkflowClientService } from './workflow-client.service';

describe('WorkflowClientService', () => {
  let service: WorkflowClientService;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(WorkflowClientService);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });
});
