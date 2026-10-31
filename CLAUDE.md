# CLAUDE.md — Workflow Engine v3

Dauerhafte Konventionen für dieses Repo. Kein Ausführungsplan (der steht in `PLAN.md`).
Claude liest diese Datei zu Beginn jeder Session.

## Wo dieser Code lebt (IMPORTANT)

v3 ist ein **Neubau** von Engine und Client und entsteht **hier, im Branch `v3`** von
`apollo29/wrkflw` — eigenständig, mit Demo-App und eigener CI. Die Coach-Admin
(`fclaenggasse/coach-admin`, git subtree unter `packages/wrkflw/`) bleibt bis zum Release
auf 2.x und stellt erst danach um.

- Fachliche Grundlage: `docs/wrkflw-anforderungen.md` in coach-admin (F-, Q-, E-Nummern).
- Visuelle Vorgabe: Design-Canvas «wrkflw Editor» (Link in `PLAN.md`).
- Code aus `main` (2.x) wird gezielt übernommen: `git show main:<pfad>`, dann anpassen.
- Releases nur über Tags `vX.Y.Z` (Engine) und `client-vX.Y.Z` (npm-Client).

## Was das Projekt ist

Eingebettete Workflow-Engine als PHP-Library. Eine **Definition** (JSON, versioniert) ist
ein Graph aus **Steps** und **Transitions**; eine **Instanz** ist ein laufender Durchlauf
mit eigenem Kontext. Steps sind `automatic`, `interactive` oder `timer`. Daten der
Host-App erreicht die Engine nur über **Datenmodelle** (Persistenz: Datenbank, REST-API,
Arbeitsspeicher oder eigene der Host-App).

## Stack

- PHP ≥ 8.4, Slim 4, Symfony ExpressionLanguage 7.4, Symfony HttpClient 7.4, libsodium
- MariaDB ≥ 10.6 / MySQL 8 (Definitionen & Kontext als JSON), Zugriff über PDO
- Angular 22, zoneless, standalone Components, Signals; TipTap 3 für den HTML-Editor
- Eigenes CSS mit Custom Properties `--wfb-*` und 12er-Raster (Container Queries), kein CSS-Framework
- Hintergrund: Cron + DB-Polling, **keine** Message-Queue

## Architektur-Grenzen (IMPORTANT)

- **YOU MUST** die Engine frei von Host-App-Abhängigkeiten halten. Die Engine definiert
  Ports (Interfaces in `backend/src/Contracts/`); die Host-App liefert Adapter.
- **NEVER** `eval()` oder direkte Ausführung von Ausdrücken — Bedingungen laufen
  ausschliesslich über den `ExpressionEvaluatorInterface`.
- Datenzugriff der Engine nur über Datenmodelle, nie direkt auf App-Tabellen.
- Zugangsdaten nie in Definitionen, Logs, Verlauf oder API-Antworten.

## Code-Konventionen

- PHP: immer `declare(strict_types=1);`, PSR-12 (php-cs-fixer), PHPStan Level max,
  sprechende Exceptions in `WorkflowEngine\Exception`, Value-Objects `readonly`.
- Bezeichner englisch; Kommentare, Doku und ADRs deutsch.
- Oberfläche deutsch, aber jeder Text über den Nachrichtenkatalog (`WF_MESSAGES`), nie
  fest im Template.
- Farben, Abstände, Radien nur über Tokens; Spaltenbreiten nur über das 12er-Raster.

## Build- & Testbefehle

- Backend (`cd backend`): `composer install`, `composer test`, `composer stan`,
  `composer cs` / `composer cs:fix`
- MariaDB für Integrationstests: `docker compose -f docker/docker-compose.yml up -d`,
  dann `WF_DB_DSN=…` setzen (siehe `.env.example`)
- Frontend (`cd frontend`): `npm ci`, `npm test` (Vitest), `npx ng build workflow-client`,
  `npx ng build demo`, `npx playwright test` (E2E; lokales Chromium per `PW_CHROMIUM_PATH`)

## Test-Erwartungen (IMPORTANT)

- **YOU MUST** für Engine-Logik die Tests **vor** der Implementierung schreiben.
- Engine-Unit-Tests nutzen In-Memory-Fakes (`backend/tests/Support/`).
- Persistenz wird mit Integrationstests gegen echtes MariaDB geprüft.
- Die Abnahme 9.15/9.16 läuft als Playwright-Suite in `frontend/e2e/`.
- Eine Phase aus `PLAN.md` ist erst fertig, wenn Backend (`test`, `stan`, `cs`) und
  Frontend (`npm test`, Builds, E2E) grün sind.

## Workflow-Gewohnheiten

- Pro `PLAN.md`-Phase eine Session; Plan zeigen, dann ausführen.
- Am Ende jeder Phase ein Commit (Conventional Commits) und ein `CHANGELOG.md`-Eintrag.
- Architekturentscheide als ADR in `docs/adr/`.
- Bei Unsicherheit: zuerst Anforderungen und `PLAN.md` lesen, dann fragen, statt zu raten.

## Projektlayout

```
backend/   src/{Contracts,Definition,Instance,Engine,Action,Model,Persistence,Rest,Security,Trigger,Http,Exception}
           bin/  migrations/  schema.sql  tests/{Unit,Integration,Support}
frontend/  projects/workflow-client/  projects/demo/  e2e/
docker/    docker-compose.yml
docs/      adr/  migration-v3.md  crontab.example  (ab Phase 5: openapi.yaml)
```
