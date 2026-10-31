# Plan: wrkflw v3 – Neubau von Engine und Client auf Branch `v3`

## Context
Grundlage ist `docs/wrkflw-anforderungen.md` (coach-admin, Stand 31.10.2026) mit dem
Design-Canvas «wrkflw Editor» (https://claude.ai/artifact/KXrLsRfSaLwM4VtVBhi2MG) als
visueller Vorgabe. `main` hat Engine 2.3.1 (PHP 8.4/Slim, ~4.9k Zeilen, gut getestet) und
Client 1.18.0 (Angular 19, Builder mit 1283 Zeilen). v3 ist eine neue Hauptversion (Q-10):
Datenmodelle ersetzen die Lese- und Schreib-Ports (3.11/5.6), REST-Datenquellen ohne Code
(F-133–F-145), ein neuer Editor nach Abschnitt 9 inkl. aller Punkte aus 9.14, Instanzen
mit Abbrechen und E-Mail-taugliche Tabellen.

**Entscheide (aus den Rückfragen):** Neubau Engine + Client · PHP 8.4 + Slim 4 · Angular 20+
zoneless · eigenes CSS mit Custom Properties `--wfb-*`, kein Framework · Migration von 2.x
mitliefern · alle geplanten Teile in v3.0 · UI deutsch, i18n-fähig, Code englisch, Doku/ADR
deutsch · PHPUnit + Vitest + Playwright · TipTap 3 · libsodium, Schlüssel aus Umgebung ·
Entwicklung hier im Branch `v3`, Release `v3.0.0` / `client-v3.0.0`.

**Leitregeln (bleiben aus main):** Ports & Adapter, die Engine kennt keine Host-App;
Bedingungen nur über `ExpressionEvaluatorInterface`; `declare(strict_types=1)`, PSR-12,
PHPStan max; Tests vor der Engine-Logik; eine Phase = ein Conventional Commit +
CHANGELOG-Eintrag, erst fertig bei grünem `composer test/stan/cs` bzw. `ng test/build`.

## Wiederverwendung aus `main` (per `git show main:<pfad>` übernehmen und anpassen, nicht neu erfinden)
- `backend/src/Engine/WorkflowEngine.php` – start/advance/handleEvent/goBack, Retry-Backoff,
  `__appliedEventIds`, `STEP_LIMIT` → Kern für Phase 1, um `cancel` erweitert.
- `backend/src/Definition/*` (`WorkflowDefinition`, `Step`, `Transition`, `WorkflowInput`,
  `DefinitionValidator`), `backend/src/Instance/{WorkflowInstance,ContextKeys}.php`.
- `backend/src/Engine/SymfonyExpressionEvaluator.php`, `EventPayloadPolicy`.
- `backend/src/Persistence/PdoWorkflowRepository.php` (SKIP-LOCKED-Claim, Versionsnummern),
  `PdoTemplateRepository.php`, `backend/schema.sql` + `migrations/`.
- `backend/src/Action/{SendEmail,SubWorkflow}Action.php`, `ActionRegistry`.
- `backend/src/Http/ApiFactory.php` + Controller, `ApiKeyAuthMiddleware`.
- Test-Fakes `backend/tests/Support/*` (`InMemoryWorkflowRepository`, `ArrayMailer`, …).
- Tooling: `backend/phpstan.neon`, `.php-cs-fixer.dist.php`, `docker/docker-compose.yml`,
  `.github/workflows/{ci,auto-release,release,publish-npm}.yml`, `scripts/version.sh`.
- Client: `flow-geometry.ts` (Fan/Merge-Pfade, `LANE_H=56`), `definition-mapping.ts`
  (`compileCondition`/`parseCondition`, `fromDefinition`/`toDefinition`, `removeStep`,
  `orderedStepNames`, `previewFlow`), `email-extensions.ts` (Platzhalter, CTA-Button),
  `workflow.status.ts`, `auth.interceptor.ts`, `docs/openapi.yaml`, ADR 1–7.

## Repo-Layout auf `v3`
```
backend/  src/{Contracts,Definition,Instance,Engine,Action,Model,Persistence,Rest,Security,Trigger,Http,Exception}
          bin/ migrations/ schema.sql tests/{Unit,Integration,Support}
frontend/ projects/workflow-client/ (Library)  projects/demo/ (Demo-App)  e2e/ (Playwright)
docker/   docs/{openapi.yaml,adr/,migration-v3.md}  CLAUDE.md  PLAN.md  CHANGELOG.md
```

## Phasen

### 0 – Fundament
- Gerüst wie oben, `composer.json` (php ≥8.4, slim 4, symfony/expression-language 7,
  symfony/http-client, psr/log; dev: phpunit 11, phpstan + phpunit-ext, php-cs-fixer).
- Angular 20 Workspace, `provideZonelessChangeDetection()`, Unit-Tests über
  `@angular/build:unit-test` (Vitest), Playwright in `frontend/e2e`.
- CI aus main übernehmen, Frontend-Job auf Vitest + Playwright (gegen Demo + MariaDB-Service).
- `CLAUDE.md`/`PLAN.md` für v3 neu (Abschnitt «Wo dieser Code lebt»: v3 hier eigenständig),
  ADR 8 «Neubau v3».

### 1 – Engine-Kern (TDD, In-Memory-Repo)
- Definition/Validator portieren; neu: eindeutige Schrittnamen, alle Fehler je Schritt
  adressierbar (für Live-Prüfung 9.14), Prüfung von Datenmodell/Feld/Freigabe (F-118).
- Instanz-Status inkl. `cancelled`; Engine: `cancel()` (offener Punkt 4), Rücksprung F-56
  inkl. Abbruch des Unter-Ablaufs, Retry Q-01, Idempotenz Q-04, Schleifenschutz Q-05.
- `EventPayloadPolicy` Standard **Enforce** (offener Punkt 1 auflösen, ADR 6 nachführen).
- Zähler «laufend» ohne abgebrochene; `updated_at` je Version.

### 2 – Datenmodelle (3.11, 5.6)
- `Model/`: readonly `DataModel` (Name, Label, ID-Feld, Felder, Teilmenge, Regeln,
  Persistenz-Referenz), `Field` (Typ text/number/bool/date/datetime, `actual`, `writable`),
  `Record` als eigener Wert, Dirty-Tracking, `ChangeOrigin` (Ablauf/Durchlauf, F-128).
- Port `PersistenceInterface` mit genau load/find/update/create (F-120), `find` mit UND und
  Obergrenze (F-122); `PdoTablePersistence`, `InMemoryPersistence`; Port für eigene
  Persistenzen der Host-App.
- `RuleInterface` (vor dem Speichern, sieht Dirty-Felder, meldet Fehler je Feld).
- `DataModelRegistry` vereint Host-Modelle + REST-Modelle, Namenskonflikt = Fehler (F-138).
- Aktionen `check_data`/`write_data` auf Datenmodelle umbauen; Katalog-Endpunkte daraus
  ableiten; Trigger-Suche über Modelle. ADR 9 «Datenmodelle statt Lese-/Schreib-Ports».

### 3 – Ablage, Worker, Migration
- `schema.sql` v3: `wf_definition` (+`updated_at`), `wf_instance` (+`cancelled`),
  `wf_history`, `wf_template`, neu `wf_rest_connection`, `wf_rest_model`, `wf_trigger`,
  `wf_trigger_lock` (Sperrfrist F-143), `wf_audit` (F-145).
- PDO-Repositories portieren/erweitern (Instanzenliste mit Filter, Versionen, Verwendung).
- `bin/run-workflows.php` (Claim, Lease, Trigger-Poll), `bin/migrate.php`.
- `migrations/3.0.0-*.sql` + `docs/migration-v3.md`: 2.x-Daten unverändert lauffähig
  (Tabellenname = Modellname, F-114); Integrationstest «2.x-Dump migrieren und weiterlaufen».

### 4 – REST-Persistenz und Sicherheit (F-129–F-145)
- `Security/SecretStoreInterface` + `SodiumSecretStore` (secretbox, `WF_SECRET_KEY`),
  CLI `bin/rotate-secret-key.php`; Geheimnisse nie in Logs/Verlauf/API.
- `Rest/HttpClient` mit SSRF-Schutz: nur HTTPS, DNS auflösen und private/interne Bereiche
  sperren, Weiterleitungen neu prüfen, optionale Allowlist, Timeout und Grössenlimit (F-144).
- `RestPersistence` (Pfade je Vorgang, Trefferliste, Teilmenge, Feldpfade), Verbindungstest
  (nur Status+Dauer), Beispielabruf ohne Speichern, Admin-Trigger mit Sperrfrist, Audit.
- ADR 10 «REST-Datenquellen und Geheimnisse».

### 5 – HTTP-API
- Bestehende Routen portieren; neu/erweitert: `GET /workflows` mit Struktur, Zählern,
  `updated_at`; `GET /workflows/{id}/versions/{v}`; `GET /instances` (Filter),
  `POST /instances/{id}/cancel`; `GET /data-models`; CRUD `/data-sources/{connections,models,triggers}`
  + `…/test`, `…/sample`, `…/check-now`; `GET /upload-handlers` endlich registrieren.
- Auth weiter als austauschbare Middleware (F-104), Admin-Recht für Datenquellen.
- `docs/openapi.yaml` v3, Controller-Tests.

### 6 – Client-Fundament
- Styles: `styles/tokens.css` (Tokens 9.12 hell + `[data-theme="dunkel"]`, `--wfb-*`),
  `grid.css` (12er-Raster mit Container Queries: breit ≥1200, mittel ≥768, schmal),
  Icon-Set (6 Schritt-Arten, 16 px, 1.4 px Strich), IBM Plex als Default.
- Primitive: Button, Segment, Pill/Badge, Feld, Banner, Dialog, Rückfrage/Undo-Toast.
- i18n: `WF_MESSAGES`-Token mit deutschem Katalog, Pipe/Funktion `t()`; Host kann ersetzen.
- `provideWorkflowAdmin({ apiBaseUrl, … })` + exportierte Routen (Übersicht, Editor
  `/workflows/:id[/v/:version]`, Instanzen, Vorlagen, Datenquellen); `canDeactivate` +
  `beforeunload` für E-08. Service-Schicht mit Signals/`httpResource`.

### 7 – Editor (9.3–9.10, 9.14)
- Store (Signals) auf Basis von `definition-mapping.ts`, erweitert um: Umbenennen zieht
  Verweise mit, keine doppelten Namen, Live-Prüfung je Schritt, sechs Schritt-Arten
  (Automatisch/Interaktiv/Timer + Workflow/Datencheck/Daten schreiben über Aktion).
- Komponenten statt Monolith: `editor-header`, `flow-preview` (Geometrie aus
  `flow-geometry.ts`, Knopf-Beschriftung statt «SONST», einklappbar mit Speicherung),
  `step-list`, `start-context`, `context-vars`, `step-detail/*` je Art, `transitions`
  (Assistent/Erweitert, «Bedingung…» öffnet den Assistenten), `add-step-cards`, `json-view`
  (Klick auf «Visuell» in visueller Ansicht tut nichts). Aktionsfelder generisch nach Typ.
- Löschen mit Undo, alles per Tastatur, Hinweistexte korrekt.

### 8 – Übersicht und Instanzen
- Übersicht 9.2 (Zeile je Workflow, Mini-Vorschau aus derselben Layout-Berechnung,
  Versionen-Aufklapper, schreibgeschützte alte Version, Entfernen mit Grund).
- Instanzen: Status-Filter, Tabelle, Verlauf (nur Feldnamen), Fehlergrund, Abbrechen.

### 9 – Runner
- Generische Darstellung interaktiver Schritte (F-50–F-56, F-85): Felder, Datei-Prüfung
  mit Fehlermeldung, Knöpfe je Ereignis, «Ein Schritt zurück» nur wenn möglich,
  idempotente Event-IDs.

### 10 – Vorlagen und HTML-Editor
- TipTap 3 portieren; Tabellen-Extension (3×3 Vorgabe, Kopfzeile, Zeile/Spalte ±,
  Inline-Styles, Breiten in %), Link/Button/Bild-URL inline statt Browser-Dialog,
  Platzhalter-Vorschläge aus den verwendenden Abläufen, Vorschau, Verwendung, Löschen mit Rückfrage.

### 11 – Datenquellen-UI (9.16)
- Verbindungen (Hinterlegt/Ersetzen, Test), Datenmodelle (Vorgänge, Trefferliste, Teilmenge,
  Beispielbaum → Feld übernehmen, Felder mit gesperrtem «×»), Trigger (Bedingungen,
  Startkontext-Zuordnung, Takt ≥5 min, Sperrfrist, «Jetzt prüfen»).

### 12 – Demo, Abnahme, Release
- Demo-App mit Beispieldaten 9.13 (Seed-Skripte), Playwright-Suite = Abnahme 9.15 (1–14)
  + 9.16, inkl. 375 px ohne waagrechtes Scrollen und Dunkelmodus.
- README, CHANGELOG `3.0.0`, ADR 11 «Client-Architektur v3», Migrationsleitfaden für die
  Coach-Admin; Tags `v3.0.0` / `client-v3.0.0`.

## Verifikation
- Je Phase: `composer test`, `composer stan`, `composer cs` (Integration gegen
  `docker compose -f docker/docker-compose.yml up -d`, `WF_DB_DSN` gesetzt).
- Frontend: `cd frontend && npm ci && ng test && ng build workflow-client && ng build demo`.
- E2E: Demo + Backend (`php -S` auf `backend/api`) + MariaDB, `npx playwright test`
  deckt 9.15/9.16 ab; visueller Abgleich mit dem Design-Canvas.
- Migration: 2.x-Schema mit Beispieldaten laden, `bin/migrate.php`, laufende Instanz
  weiterführen, alte Definition unverändert starten.
