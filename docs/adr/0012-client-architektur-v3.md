# 12. Client-Architektur v3

Status: akzeptiert (2026-11-05, Neubau 3.0 Phase 12)

## Kontext

Client 1.18 war ein Angular-19-Modul mit einem Builder von gut 1200 Zeilen, festen
Pixelbreiten, Texten im Template und Browser-Dialogen für Link und Bild. 9.14 listet die
Folgen. v3 baut den Client neu und muss sich in die Coach-Admin einbetten lassen, ohne
deren Gestaltung, Anmeldung oder Dateiablage zu kennen.

## Entscheid

- **Angular ≥ 22, standalone, zoneless, Signals.**
  - Komponenten sind `OnPush`.
  - Zustand liegt in Signals, Ladezustand über `httpResource` und Signals.
  - Es gibt kein NgModule und kein RxJS im Zustand.
- **Einbinden über eine Funktion und Routen.**
  - `provideWorkflowAdmin({ apiBaseUrl, … })` und `workflowAdminRoutes`.
  - Editor, Instanzen, Runner, Vorlagen und Datenquellen werden erst bei Bedarf geladen.
  - Der HTML-Editor (TipTap) kommt per `@defer`.
  - So bleibt der Startanteil klein (Demo < 500 kB).
- **Host-Funktionen statt eigener Infrastruktur.**
  - Datei-Upload (`uploadFile`) und Bild-Upload (`uploadImage`) liefert die Host-App.
  - Die Anmeldung kommt als `authToken()` oder `apiKey`; der Header geht nur an
    `apiBaseUrl`.
- **Texte nur aus dem Katalog.**
  - `WF_MESSAGES` mit deutschem Standard, ersetzbar je Text oder als ganze Sprache.
  - Ein Test bricht ab, wenn ein Template festen Text enthält.
- **Gestaltung nur über Tokens.**
  - `--wfb-*` für Farben, Abstände, Radien und Schrift.
  - Hell und dunkel über `data-theme`.
  - Breiten nur über das 12er-Raster mit Container Queries (breit, mittel, schmal): der
    Client richtet sich nach seinem Platz in der Host-App, nicht nach dem Fenster.
- **Eine API-Schicht.**
  - `WorkflowApi` kennt alle Routen, `WfApiError` das einheitliche Fehlerformat aus ADR 11.
  - Feldfehler erscheinen am Feld.
- **Sicherheit im Client.**
  - Platzhalterwerte werden escaped.
  - Die Vorschau läuft im `iframe` mit `sandbox` ohne Skripte, kein Umgehen des
    Sanitizers.
  - Link- und Bildziele werden geprüft.
  - Zugangsdaten werden nie angezeigt.
- **Ersatz-Backend nur in der Demo.**
  - Die Library enthält keinen Fake.
  - Die Demo tauscht ihn über die Build-Konfiguration `echt` gegen die echte Engine aus
    (`docs/demo.md`).

## Folgen

- Die Host-App braucht Angular ≥ 22 und muss zoneless laufen können.
- Eigene Gestaltung geht über Tokens; tiefere Eingriffe in das CSS sind nicht vorgesehen.
- Neue Texte und Sprachen brauchen keinen Eingriff in den Code.
- Die Abnahme läuft zweimal: schnell gegen das Ersatz-Backend und einmal gegen Engine und
  MariaDB.
