import { Routes } from '@angular/router';
import { workflowAdminRoutes } from '@apollo29/workflow-client';

// Demo-Seiten werden erst bei Bedarf geladen.
export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'admin' },
  { path: 'admin', children: workflowAdminRoutes },
  {
    path: 'bausteine',
    loadComponent: () => import('./gallery').then((m) => m.Gallery),
    title: 'Bausteine – wrkflw Demo',
  },
  {
    path: 'durchspielen',
    loadComponent: () => import('./playground').then((m) => m.Playground),
    title: 'Durchspielen – wrkflw Demo',
  },
];
