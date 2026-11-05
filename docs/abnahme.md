# Abnahme 3.0

Wie jeder Punkt aus 9.14, 9.15 und 9.16 (`docs/wrkflw-anforderungen.md` in coach-admin)
belegt ist. E2E-Tests liegen in `frontend/e2e/` (Demo mit Ersatz-Backend, breit und 375 px)
und `frontend/e2e-echt/` (Demo mit Engine, MariaDB und Beispieldaten 9.13). Vitest-Tests
liegen neben dem Code in `frontend/projects/workflow-client/src/lib/`.

Ausführen:

```bash
cd frontend
npx playwright test                                # Ersatz-Backend
npx playwright test -c playwright.echt.config.ts   # echtes Backend (siehe docs/demo.md)
```

## 9.15 Abnahme

| Nr. | Punkt | Beleg |
| --- | --- | --- |
| 1 | Sechs Schritt-Arten anlegen, einstellen, löschen | `abnahme.spec.ts` › 9.15-1; `editor.spec.ts` › Löschen mit Rückgängig |
| 2 | Beispielablauf 9.13 wie beschrieben | `abnahme.spec.ts` › 9.15-2; `editor.spec.ts` › öffnet mit dem ersten Schritt …; `echt.spec.ts` › Übersicht |
| 3 | Plättchen wählt denselben Schritt wie die Liste | `abnahme.spec.ts` › 9.15-3 |
| 4 | Eingaben ändern Vorschau und Kontext-Variablen sofort | `abnahme.spec.ts` › 9.15-4; `editor/model/context-vars.spec.ts` |
| 5 | Bedingung im Assistenten, Ausdruck sichtbar | `abnahme.spec.ts` › 9.15-5; `editor.spec.ts` › Bedingung im Assistenten … |
| 6 | Visuell ↔ JSON ohne Verlust | `abnahme.spec.ts` › 9.15-6; `editor/model/definition-mapping.spec.ts` |
| 7 | Speichern mit Meldung, Versionspille stimmt | `abnahme.spec.ts` › 9.15-7; `editor.spec.ts` › Aktiv mit Fehler gesperrt …; `echt.spec.ts` › Editor |
| 8 | Übersicht über die ganze Breite, Klick öffnet den Editor | `abnahme.spec.ts` › 9.15-8; `overview.spec.ts` |
| 9 | Ältere Versionen: entfernbar oder gesperrt mit Grund | `abnahme.spec.ts` › 9.15-9; `overview.spec.ts` › ältere Versionen … |
| 10 | Nur Bearbeitung, Verlassen fragt nach | `abnahme.spec.ts` › 9.15-10; `editor.spec.ts` › Verlassen mit Änderungen fragt nach |
| 11 | Vorlagen mit Tabellen, Vorschau, Verwendung | `abnahme.spec.ts` › 9.15-11; `templates.spec.ts`; `echt.spec.ts` › Vorlagen |
| 12 | Hell und dunkel, 375 px ohne waagrechtes Scrollen | `abnahme.spec.ts` › 9.15-12 (alle Reiter, beide Themen); Projekt `schmal` aller Suiten |
| 13 | 12er-Raster in drei Stufen | `abnahme.spec.ts` › 9.15-13 (1440, 1000, 375 px); `styles/grid.css` |
| 14 | Alle Punkte aus 9.14 | siehe nächste Tabelle |

## 9.14 Verbesserungen

| Thema | Beleg |
| --- | --- |
| Klick auf «Visuell» in der visuellen Ansicht tut nichts | `abnahme.spec.ts` › 9.15-6 |
| «Bedingung…» öffnet den Assistenten | `editor.spec.ts` › Bedingung im Assistenten …; `abnahme.spec.ts` › 9.15-5 |
| Archiv öffnet die gewählte Version schreibgeschützt | `abnahme.spec.ts` › 9.15-14; `overview.spec.ts` › ältere Versionen … |
| Umbenennen zieht Verweise mit | `editor.spec.ts` › Schritt per Karte hinzufügen, umbenennen …; `editor/model/operations.spec.ts` |
| Doppelte Namen verhindert | `abnahme.spec.ts` › 9.15-14; `editor/model/operations.spec.ts` |
| Rückfrage beim Verlassen (E-08) | `editor.spec.ts` › Verlassen …; `templates.spec.ts`; `data-sources/data-sources-page.spec.ts` |
| Löschen mit Rückfrage oder Rückgängig | `editor.spec.ts` › Löschen mit Rückgängig; `overview.spec.ts` › v2 entfernen mit Rückfrage |
| Fehler laufend am Schritt | `editor.spec.ts` › Aktiv mit Fehler gesperrt …; `abnahme.spec.ts` › 9.15-14 |
| Knopfbeschriftung in der Spur | `abnahme.spec.ts` › 9.15-14; `editor/model/flow-geometry.spec.ts` |
| Lesbare Aktionsnamen, keine doppelten Karten | `abnahme.spec.ts` › 9.15-14 |
| Abgebrochene zählen als beendet | `abnahme.spec.ts` › 9.15-14; Backend `PdoWorkflowRepository` (`RUNNING_SQL`) mit Integrationstest |
| Zeitpunkt der letzten Änderung je Version | `overview.spec.ts`; Backend-Integrationstests der Ablage |
| Spaltenbreiten im 12er-Raster | `abnahme.spec.ts` › 9.15-13 |
| Typ-Karten 2 von 12, sechs in einer Reihe | `abnahme.spec.ts` › 9.15-13 |
| Platzhalter aus den verwendenden Abläufen | `templates.spec.ts` › Verwendung ablesen, Vorschläge nutzen …; `templates/template-manager.spec.ts` |
| Link, Button, Bild-URL ohne Browser-Dialog | `editor/html/html-editor.spec.ts` |
| Alles per Tastatur | `editor.spec.ts` › Schrittliste per Tastatur; `shell.spec.ts` › Reiter per Tastatur; `runner.spec.ts` › Tastatur |
| Hinweistexte stimmen mit dem Verhalten | `editor/components/components.spec.ts` |

## 9.16 Datenquellen

| Punkt | Beleg |
| --- | --- |
| Verbindung anlegen, nur `https://`, interne Adressen mit Grund abgewiesen | `data-sources.spec.ts`; `echt.spec.ts` › Datenquellen |
| Zugangsdaten nur hinterlegen und ersetzen, nie anzeigen | `data-sources.spec.ts` (Seite und Browser-Speicher); `echt.spec.ts` (alle API-Antworten) |
| Verbindungstest nur mit Status und Dauer | `data-sources.spec.ts`; Backend `DataSourceAdminTest` |
| Datenmodell: Vorgänge, Trefferliste, Teilmenge | `data-sources/data-source-forms.spec.ts`; `data-sources-page.spec.ts` |
| Beispiel abrufen, Feld übernehmen | `data-sources.spec.ts` › Datenmodell … |
| «×» bei verwendeten Feldern gesperrt mit Grund | `data-sources.spec.ts`; `data-sources-page.spec.ts` |
| Trigger: Bedingungen, Startkontext, Takt ≥ 5 min, Sperrfrist | `data-sources.spec.ts` › Trigger …; `echt.spec.ts` › Trigger |
| «Jetzt prüfen» startet nichts | `data-sources.spec.ts`; `echt.spec.ts` |
| Protokoll | `data-sources.spec.ts`; `echt.spec.ts` |
| Löschen gesperrt bei Verwendung | `data-sources.spec.ts` › verwendete Verbindung … |
