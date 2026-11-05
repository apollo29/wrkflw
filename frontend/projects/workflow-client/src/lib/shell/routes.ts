import { Routes } from '@angular/router';
import { WfAdminShell } from './admin-shell';
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
/** Grosse Seiten werden erst bei Bedarf geladen (in Anwendungen, die die Quelle bündeln). */
const editor = () => import('../editor/components/workflow-editor').then((m) => m.WfWorkflowEditor);

export const workflowAdminRoutes: Routes = [
  {
    path: '',
    component: WfAdminShell,
    children: [
      { path: '', pathMatch: 'full', component: WfWorkflowOverview },
      {
        path: 'workflows/neu',
        loadComponent: editor,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'workflows/:id',
        loadComponent: editor,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'workflows/:id/v/:version',
        loadComponent: editor,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'instanzen',
        loadComponent: () => import('../instances/instance-list').then((m) => m.WfInstanceList),
      },
      {
        path: 'instanzen/:id',
        loadComponent: () => import('../runner/runner-page').then((m) => m.WfRunnerPage),
      },
      {
        path: 'vorlagen',
        loadComponent: () =>
          import('../templates/template-manager').then((m) => m.WfTemplateManager),
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'datenquellen',
        component: WfPlaceholderPage,
        data: { title: 'nav.dataSources', phase: 11 },
      },
    ],
  },
];
