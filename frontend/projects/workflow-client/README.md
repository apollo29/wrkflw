# @apollo29/workflow-client

Angular-Client der Workflow-Engine v3: Übersicht, Editor, Instanzen, Vorlagen und
Datenquellen. Veröffentlicht in GitHub Packages (Tag `client-vX.Y.Z`). Standalone,
zoneless, Signals; Angular ≥ 22.2.

> Im Aufbau (siehe `PLAN.md`). Shell, Gestaltung, Bausteine, Texte, API-Schicht, Editor,
> Übersicht, Instanzen und Runner sind da; Vorlagen und Datenquellen folgen.

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

## Editor

`workflowAdminRoutes` enthält den Editor unter `workflows/neu`, `workflows/:id` und
`workflows/:id/v/:version` (ältere Versionen schreibgeschützt). Er braucht von der API
`/workflows`, `/workflows/validate`, `/actions`, `/data-models`, `/upload-handlers` und
`/templates`. Fehlende Kataloge sind kein Fehler: die Auswahl bleibt leer und das Feld
erklärt es.

Der HTML-Editor für E-Mail-Inhalte (`wf-html-editor`, TipTap 3) wird erst geladen, wenn
ein solches Feld erscheint. Die Modell-Funktionen (`fromDefinition`, `toDefinition`,
`renameStep`, `flowSections` …) sind exportiert und ohne Angular nutzbar.

## Runner

`<wf-workflow-runner [instanceId]="id" (finished)="…" (stepChange)="…" />` zeigt einen
Durchlauf für Endnutzer an, etwa auf der öffentlichen Seite der Host-App. Ereignisse tragen
einen `Idempotency-Key`; ein Doppelklick wirkt einmal.

Datei-Felder lädt die Host-App hoch:

```ts
provideWorkflowAdmin({
  apiBaseUrl: '/api',
  uploadFile: async (file, { handler, instanceId, field }) => {
    const res = await myUpload(file, handler);
    return res.ok ? { value: res.fileId } : { rejected: res.reason };
  },
});
```

Ohne `uploadFile` zeigt der Runner bei Datei-Feldern nur einen Hinweis.
