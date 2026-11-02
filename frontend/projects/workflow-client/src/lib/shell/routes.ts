import { Routes } from '@angular/router';
import { WfAdminShell } from './admin-shell';
import { WfWorkflowEditor } from '../editor/components/workflow-editor';
import { WfInstanceList } from '../instances/instance-list';
import { WfWorkflowOverview } from '../overview/workflow-overview';
import { WfPlaceholderPage } from './placeholder-page';
import { wfUnsavedChangesGuard } from './unsaved-changes';

/**
 * Routen der Admin-Oberfläche, unter einem beliebigen Pfad der Host-App einhängbar:
 *
 * ```ts
 * { path: 'admin', children: workflowAdminRoutes }
 * ```
 *
 * Die Host-App aktiviert `withComponentInputBinding()` im Router.
 */
export const workflowAdminRoutes: Routes = [
  {
    path: '',
    component: WfAdminShell,
    children: [
      { path: '', pathMatch: 'full', component: WfWorkflowOverview },
      {
        path: 'workflows/neu',
        component: WfWorkflowEditor,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'workflows/:id',
        component: WfWorkflowEditor,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'workflows/:id/v/:version',
        component: WfWorkflowEditor,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      { path: 'instanzen', component: WfInstanceList },
      {
        path: 'vorlagen',
        component: WfPlaceholderPage,
        data: { title: 'nav.templates', phase: 10 },
      },
      {
        path: 'datenquellen',
        component: WfPlaceholderPage,
        data: { title: 'nav.dataSources', phase: 11 },
      },
    ],
  },
];
