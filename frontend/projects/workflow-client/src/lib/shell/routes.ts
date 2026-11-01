import { Routes } from '@angular/router';
import { WfAdminShell } from './admin-shell';
import { WfEditorPlaceholder, WfPlaceholderPage } from './placeholder-page';
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
      {
        path: '',
        pathMatch: 'full',
        component: WfPlaceholderPage,
        data: { title: 'nav.workflows', phase: 8 },
      },
      {
        path: 'workflows/:id',
        component: WfEditorPlaceholder,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'workflows/:id/v/:version',
        component: WfEditorPlaceholder,
        canDeactivate: [wfUnsavedChangesGuard],
      },
      {
        path: 'instanzen',
        component: WfPlaceholderPage,
        data: { title: 'nav.instances', phase: 8 },
      },
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
