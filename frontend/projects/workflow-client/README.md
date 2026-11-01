# @apollo29/workflow-client

Angular-Client der Workflow-Engine v3: Übersicht, Editor, Instanzen, Vorlagen und
Datenquellen. Veröffentlicht in GitHub Packages (Tag `client-vX.Y.Z`). Standalone,
zoneless, Signals; Angular ≥ 22.2.

> Im Aufbau (siehe `PLAN.md`). Phase 6 liefert Shell, Gestaltung, Bausteine, Texte und
> API-Schicht; die fachlichen Seiten folgen ab Phase 7.

## Einbinden

```ts
// app.config.ts
import { provideHttpClient, withFetch } from '@angular/common/http';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { provideWorkflowAdmin, workflowAdminRoutes } from '@apollo29/workflow-client';

export const appConfig: ApplicationConfig = {
  providers: [
    provideHttpClient(withFetch()),
    provideRouter([{ path: 'workflows-admin', children: workflowAdminRoutes }], withComponentInputBinding()),
    provideWorkflowAdmin({
      apiBaseUrl: '/api/wrkflw',
      authToken: () => session.token(), // oder apiKey: '…'
      theme: 'system', // 'hell' | 'dunkel' | 'system'
    }),
  ],
};
```

Gestaltung in `angular.json` einbinden; Schriften (IBM Plex Sans/Mono) lädt die Host-App:

```json
"styles": ["node_modules/@apollo29/workflow-client/styles/wf.css", "src/styles.css"]
```

Der Anmelde-Header geht nur an `apiBaseUrl`, nie an andere Adressen.

## Gestaltung anpassen

Alles liegt unter `.wf` und richtet sich nach Tokens (`styles/tokens.css`). Überschreiben:

```css
.wf {
  --wfb-primary: #0f766e;
  --wfb-font-sans: 'Inter', system-ui, sans-serif;
}
```

Dunkel: `data-theme="dunkel"` am Element mit `.wf` (setzt die Shell selbst). Spalten
richten sich nach dem verfügbaren Platz (Container Queries): breit ≥ 1200 px, mittel,
schmal < 768 px mit 44-px-Touch-Zielen.

## Texte ersetzen

Jeder Text kommt aus dem Katalog `MESSAGES_DE`. Einzelne Texte oder eine ganze Sprache:

```ts
provideWorkflowAdmin({ apiBaseUrl: '/api', messages: { 'nav.instances': 'Läufe' } });
```

In eigenen Templates: `{{ 'nav.workflows' | wfT }}`, im Code `inject(WfI18n).t(…)`.

## Bausteine

`wf-icon`, `button[wfButton]`, `wf-segment`, `wf-pill`, `wf-field`, `wf-banner`,
`WfDialogService.confirm()`, `WfToastService` (mit «Rückgängig»), `WorkflowApi`
(alle Routen der API, Fehler als `WfApiError`), `WorkflowResources` (Listen als
`httpResource`). Seiten mit Eingaben schützen sich mit `wfUnsavedChangesGuard` und
`wfBeforeUnload()` vor Verlust (E-08). Die Demo zeigt alle Bausteine unter `/bausteine`.
