import { Routes } from '@angular/router';
import { workflowAdminRoutes } from '@apollo29/workflow-client';
import { Gallery } from './gallery';

export const routes: Routes = [
  { path: '', pathMatch: 'full', redirectTo: 'admin' },
  { path: 'admin', children: workflowAdminRoutes },
  { path: 'bausteine', component: Gallery, title: 'Bausteine – wrkflw Demo' },
];
