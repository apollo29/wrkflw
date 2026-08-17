# CLAUDE.md — Workflow Engine

Dauerhafte Konventionen für dieses Repo. Kein Ausführungsplan (der steht in `PLAN.md`).
Claude liest diese Datei zu Beginn jeder Session.

## Wo dieser Code lebt (IMPORTANT)

Dieses Repo liegt zusätzlich als **git subtree** unter `packages/wrkflw/` im Repo
`fclaenggasse/coach-admin`; dort findet die aktive Entwicklung statt. Beim Arbeiten
gilt deshalb:

- **In coach-admin:** Engine und Host-App im selben Commit ändern. `composer install`
  bzw. `npm install` sind nach Engine-Änderungen **nicht** nötig — das Backend hängt
  per Composer-path-Repo (Symlink) daran, das Frontend per tsconfig-`paths` auf
  `frontend/projects/workflow-client/src/public-api.ts`.
- **NEVER** in coach-admin ein `npm install` innerhalb von `packages/wrkflw/frontend/`
  ausführen — das erzeugt eine zweite Angular-Kopie und bricht das App-Bundle. Die
  npm-Pakete kommen dort aus dem Workspace im coach-admin-Root.
- **In diesem Repo eigenständig:** unverändert `composer install` und
  `cd frontend && npm ci`.
- Rückweg ins öffentliche Repo: `./scripts/wrkflw-sync.sh push <branch>` (in
  coach-admin). Releases weiterhin nur hier, über Tags `vX.Y.Z` / `client-vX.Y.Z`.

## Was das Projekt ist

Eingebettete Workflow-Engine als PHP-Library. Eine **Definition** (JSON, versioniert) ist
ein Graph aus **Steps** und **Transitions**; eine **Instanz** ist ein laufender Durchlauf
mit eigenem Kontext. Steps sind `automatic` (Hintergrund), `interactive` (Frontend führt
den Benutzer) oder `timer` (vom Cron aufgeweckt).

## Stack

- PHP ≥ 8.1, Slim 4, Symfony ExpressionLanguage
- MariaDB (Definitionen & Kontext als JSON), Zugriff über PDO
- Angular (standalone Components, Signals)
- Hintergrund: Cron + DB-Polling, **keine** Message-Queue

## Architektur-Grenzen (IMPORTANT)

- **YOU MUST** die Engine frei von Host-App-Abhängigkeiten halten. Die Engine definiert
  Ports (Interfaces in `src/Contracts/`); die Host-App liefert Adapter. Kein Code in
  `src/Engine`, `src/Definition`, `src/Instance` darf konkrete App-Klassen kennen.
- **NEVER** `eval()` oder direkte Ausführung von Ausdrücken — Bedingungen laufen
  ausschließlich über den `ExpressionEvaluatorInterface`.
- Datenzugriff der Engine nur über `DataProviderInterface`, nie direkt auf App-Tabellen.

## Code-Konventionen

- Immer `declare(strict_types=1);`
- PSR-12, geprüft via php-cs-fixer
- PHPStan Level max, keine Fehler
- Sprechende Exceptions im Namespace `WorkflowEngine\Exception`
- Value-Objects bevorzugt `readonly`

## Build- & Testbefehle

- `composer install`
- `composer test` — PHPUnit (Unit + Integration)
- `composer stan` — statische Analyse
- `composer cs` / `composer cs:fix` — Code-Style
- `docker compose up -d` — MariaDB für Integrationstests
- Frontend: `cd frontend && npm ci && ng test && ng build`

## Test-Erwartungen (IMPORTANT)

- **YOU MUST** für Engine-Logik die Tests **vor** der Implementierung schreiben.
- Engine-Unit-Tests nutzen einen In-Memory-Fake des `WorkflowRepositoryInterface`.
- Persistenz wird mit Integrationstests gegen echtes MariaDB geprüft.
- Eine Phase aus `PLAN.md` ist erst fertig, wenn `composer test`, `composer stan` und
  `composer cs` grün sind.

## Workflow-Gewohnheiten

- Pro `PLAN.md`-Phase eine Session; im Plan-Modus starten, Plan zeigen, dann ausführen.
- Am Ende jeder Phase ein Commit (Conventional Commits) und ein `CHANGELOG.md`-Eintrag.
- Bei Unsicherheit über das Verhalten: zuerst den entsprechenden Abschnitt in `PLAN.md`
  lesen, dann fragen, statt zu raten.

## Projektlayout

```
backend/   src/{Contracts,Definition,Instance,Engine,Action,Persistence,Exception}
           api/  bin/  examples/  tests/{Unit,Integration}  schema.sql
frontend/  projects/workflow-client/  + Demo-App
docker/    docker-compose.yml
docs/      openapi.yaml  adr/
```
