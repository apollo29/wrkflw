import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import type { Instance } from '../api/models';
import { WorkflowApi } from '../api/workflow-api';
import { WfTranslatePipe } from '../i18n/i18n';
import { WfButton } from '../ui/button';
import { WfWorkflowRunner } from './workflow-runner';

/** Admin-Seite `instanzen/:id`: einen wartenden Durchlauf bearbeiten. */
@Component({
  selector: 'wf-runner-page',
  imports: [RouterLink, WfButton, WfTranslatePipe, WfWorkflowRunner],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-run-page' },
  template: `
    <div class="wf-ed-row">
      <a wfButton variant="text" routerLink=".." queryParamsHandling="preserve">{{
        'runner.page.back' | wfT
      }}</a>
      @if (instance(); as i) {
        <span class="wf-ed-divider" aria-hidden="true"></span>
        <span class="wf-mono wf-muted">{{ i.definitionId }} · v{{ i.definitionVersion }}</span>
        @if (i.subjectId) {
          <span class="wf-muted">{{ 'runner.page.subject' | wfT: { subject: i.subjectId } }}</span>
        }
      }
    </div>
    <wf-workflow-runner [instanceId]="id()" (stepChange)="reload()" />
  `,
})
export class WfRunnerPage {
  readonly id = input.required<string>();
  private readonly api = inject(WorkflowApi);
  protected readonly instance = signal<Instance | null>(null);

  constructor() {
    effect(() => {
      this.id();
      untracked(() => this.reload());
    });
  }

  protected reload(): void {
    firstValueFrom(this.api.instances.get(this.id()))
      .then((i) => this.instance.set(i))
      .catch(() => this.instance.set(null));
  }
}
