# Changelog

Alle nennenswerten Aenderungen an diesem Projekt werden hier dokumentiert.
Format orientiert an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased]

## [1.6.0] - 2026-07-04

### Changed
- **Reicher WYSIWYG-Editor (client 1.4.0):** `wf-html-editor` intern auf **TipTap**
  umgestellt — Formatier-Toolbar (Fett/Kursiv/Unterstrichen/Durchgestrichen, H2/H3,
  Aufzählung/Nummerierung, Zitat, Ausrichtung, Link, Undo/Redo, Format entfernen),
  HTML-Quelltext-Umschalter, Platzhalter-Einfügung (`{{feld}}`) und neue
  **Live-Vorschau** mit aufgelösten Beispielwerten (optionaler Input `[sampleContext]`,
  sonst Heuristik). Die API (`[(value)]` / `[placeholders]`) bleibt unverändert, sodass
  die Builder-Integration gleich bleibt. TipTap-Abhängigkeiten werden für Consumer
  automatisch mitinstalliert (`allowedNonPeerDependencies` in `ng-package.json`).
- Demo-Bundle-Budget angehoben (TipTap-Größe).

## [1.5.0] - 2026-07-04

### Added
- **Templating / WYSIWYG (client 1.3.0):** neuer Config-Feldtyp `html` und ein
  abhängigkeitsfreier Editor `wf-html-editor` (`HtmlEditorComponent`) mit
  Formatier-Toolbar (Fett/Kursiv/Unterstrichen/H2/Liste/Link), HTML-Quelltext-
  Umschalter und **Platzhalter-Einfügung** (`{{feld}}`, gespeist aus den
  interaktiven Feldern des Workflows plus eigenen Namen). Der Builder rendert das
  Feld automatisch für Actions, die ein `html`-Config-Feld melden.
- Backend: `SendEmailAction` meldet `body` jetzt als Typ `html` (HTML-E-Mail-
  Template mit `{{platzhalter}}`; Interpolation unverändert).

### Fixed
- **Builder (client 1.2.2):** CSS-Spezifität der Button-/Input-Varianten korrigiert —
  die Basis-Regeln (`.wfb button`, `.wfb input[…]`) überstimmten die Varianten-
  Klassen (`.wfb__typecard`, `.wfb__step`, `.wfb__load`, …), wodurch u.a. die
  Typ-Auswahl-Karten auf Button-Höhe gequetscht wurden. Varianten tragen jetzt
  den `.wfb`-Präfix; Typ-Karten zusätzlich mit `min-height` und vertikal
  zentriertem Inhalt.
- **Builder (client 1.2.1):** Die Schritt-Liste links zeigt die Schritte jetzt in
  Ablauf-Reihenfolge (BFS ab Start-Schritt, identisch zur Ablauf-Vorschau) statt
  in der rohen Array-Reihenfolge der Definition; nicht erreichbare Schritte
  folgen am Ende. Auswahl/Löschen laufen weiter über den Original-Index.

### Changed
- `publish-npm`-Workflow legt bei `client-v*`-Tags zusätzlich automatisch einen
  GitHub-Release (nicht „Latest") mit dem gepackten `.tgz` an.
- **Builder-Redesign (client 1.2.0):** `wf-builder` nach Design-Vorlage komplett
  neu gestylt — typisierte Ablauf-Chips mit Icons und Pfeilen (Blau/Violett/Amber
  für automatic/interactive/timer, aktiver Schritt umrandet), Schritt-Detail mit
  Typ-Badge („Interaktiv · wartet auf Eingabe"), Eingabefelder als Zeilenliste,
  Bedingungs-Assistent als hervorgehobenes blaues Panel („Wenn … ist … → gehe zu …")
  mit kompiliertem Ausdruck als Vorschau-Zeile, bedingungslose Übergänge als
  „Sonst → gehe zu …"-Zeile, Typ-Auswahl-Karten mit Beschreibung am Seitenende,
  segmentierter Visuell/JSON-Umschalter. Styles jetzt in eigener CSS-Datei mit
  Theming-Hooks (`--wfb-primary`, `--wfb-border`, `--wfb-bg`, `--wfb-bg-soft`,
  `--wfb-text`, `--wfb-text-muted`, `--wfb-radius`) und brauchbaren Defaults —
  Host-Apps brauchen keine ::ng-deep-Brücke mehr. Funktionalität/API unverändert.

## [1.4.0] - 2026-07-03

### Added
- No-Code-Workflow-Builder (Admin-GUI) in der Angular-Library: `WorkflowBuilderComponent`
  (`wf-builder`) mit geführter Schrittliste, typabhängigen Konfigurations-Formularen,
  Bedingungs-Assistent (Feld/Operator/Wert) samt „Erweitert"-Rohausdruck, read-only
  Ablauf-Vorschau und Visuell/JSON-Umschalter. Erzeugt dieselbe Definition-JSON wie der
  rohe Editor; Server-Validierung bleibt maßgeblich.
- Reines Mapping-Modul `definition-mapping.ts` (Modell↔JSON, `compileCondition`/
  `parseCondition`, BFS-Reihenfolge) mit eigenen Unit-Tests.
- Backend `GET /actions`: Action-Katalog mit optionalem Config-Schema. Neues optionales
  `ConfigurableActionInterface` (rein additiv); `SendEmailAction` beschreibt `to/subject/body`;
  `ActionRegistry::keys()`; `ActionController`; Route in `ApiFactory` (Container- und
  Direkt-Pfad) + Container-Wiring in `examples/bootstrap.php`.
- `WorkflowService.listActions()`; Katalog-Typen in `workflow.models.ts`.
- Demo: „Editor"-Tab nutzt jetzt den Builder; `proxy.conf.json` um `/actions` ergänzt.

### Changed
- `ApiFactory::create()` akzeptiert optional eine `ActionRegistry`, um die `/actions`-Route
  auch ohne Container zu registrieren.

## [1.3.1] - 2026-06-30

### Changed
- npm-Publish-Workflow veroeffentlicht jetzt in die **GitHub-Packages**-npm-Registry
  (`npm.pkg.github.com`) via `GITHUB_TOKEN` — kein `NPM_TOKEN`-Secret mehr noetig.
- Angular-Library um `repository`-Feld ergaenzt (verknuepft das Paket mit dem Repo).
- README: Bezug ueber GitHub Packages dokumentiert; Hinweis, dass die PHP-Engine
  nicht ueber GitHub Packages, sondern via VCS/Packagist bezogen wird.

## [1.3.0] - 2026-06-30

### Added
- Root-`composer.json` (`apollo29/workflow-engine`): macht die PHP-Engine direkt per
  Composer (VCS/Packagist) einbindbar; Autoload `WorkflowEngine\` → `backend/src`.
- npm-Publish-Workflow (`.github/workflows/publish-npm.yml`): veroeffentlicht die
  Angular-Library bei Tags `client-v*` (benoetigt Repo-Secret `NPM_TOKEN`).
- README-Abschnitt „Einbinden in andere Projekte" (Composer + npm).

### Changed
- Angular-Library auf scoped Namen `@apollo29/workflow-client` mit Version `1.0.0`
  umgestellt (inkl. `publishConfig.access=public`); Demo-Importe und tsconfig-Pfad
  entsprechend angepasst.

## [1.2.0] - 2026-06-30

### Added
- Definitions-Verwaltung (API): `GET /workflows` (Liste), `GET /workflows/{def}`
  (aktive Definition), `POST /workflows/{def}` (neue Version anlegen, validiert via
  `DefinitionValidator`, wird aktiv) — neuer `DefinitionController` und Repository-
  Methoden `listDefinitions()`, `findDefinitionJson()`, `saveDefinition()`.
- Angular: `WorkflowEditorComponent` (Liste + JSON-Editor + lokale & Engine-Validierung)
  sowie Service-Methoden `listDefinitions()`/`getDefinition()`/`saveDefinition()`.
  Demo-App mit Tabs **Runner** / **Editor**.
- `scripts/demo.sh`: startet den kompletten Stack (MariaDB → Seed → API → ng serve)
  inkl. Dev-Proxy (kein CORS).
- Integrations- und Endpunkt-Tests fuer die Definitions-Verwaltung; Frontend-Tests
  fuer Service und Editor-Komponente.

## [1.1.0] - 2026-06-30

### Added
- PSR-11-Container-Wiring (php-di) fuer die API: `examples/bootstrap.php` stellt
  `buildContainer()` bereit; `ApiFactory::createFromContainer()` loest den
  `WorkflowController` ueber den Container auf. `api/index.php` nutzt diesen Weg.
- Beispiel-`AppDataProvider` implementiert `find()` echt (Whitelist-Tabellen,
  sichere Spaltennamen, prepared statements) statt eines Stubs.

### Changed
- `schema.sql` nutzt `CREATE TABLE IF NOT EXISTS` — `bin/migrate.php` ist damit
  gefahrlos wiederholbar.

## [1.0.1] - 2026-06-30

### Changed
- Idempotenz: gespeicherte Event-Keys pro Instanz werden auf die jüngsten 50 begrenzt
  (kein unbegrenztes Wachstum des Kontexts).
- CI: Actions auf Node-24-Versionen gehoben (`actions/checkout@v5`,
  `actions/setup-node@v5`, `actions/cache@v6`) — Deprecation-Warnungen entfernt.

## [1.0.0] - 2026-06-30

### Added — CI/CD
- GitHub-Actions-Pipeline (`.github/workflows/ci.yml`): Backend-Job (PHP 8.4 mit
  MariaDB-11.4-Service — `composer cs`, `stan`, `test` inkl. Integrationstests) und
  Frontend-Job (Node 22 — `ng build` Library/Demo, headless Unit-Tests).
- `frontend/karma.conf.js` mit `ChromeHeadlessCI`-Launcher (`--no-sandbox`) fuer CI.
- Release-Workflow (`.github/workflows/release.yml`): erstellt bei Tags `v*` ein
  GitHub Release aus dem passenden CHANGELOG-Abschnitt.

### Added — Robustheit & Repo
- Lease-Timeout im `WorkflowRunner`: haengende `running`-Instanzen werden nach einer
  konfigurierbaren Spanne (Default 300 s) erneut abgeholt — `claimDueInstances()` mit
  `staleAfterSeconds`, `updated_at` als Lease. Schuetzt vor Worker-Abstuerzen zwischen
  Claim und Verarbeitung.
- MIT-`LICENSE`-Datei (passend zur `composer.json`-Deklaration).

### Added — Phase 9: Härtung & Dokumentation
- Retry/Backoff für fehlgeschlagene Actions: erneutes Einplanen als `waiting_timer`
  mit exponentiellem Backoff bis `maxAttempts`, danach `failed`; neue Spalte `attempts`
  auf `wf_instance` (bei jedem Schrittwechsel zurückgesetzt).
- Idempotentes Event-Handling: `handleEvent()` mit optionalem Idempotenz-Key
  (API-Header `Idempotency-Key`); doppelte Events sind No-ops (`event_duplicate`).
- Strukturiertes Logging im `WorkflowRunner` über optionalen PSR-3-Logger.
- Versionierungs-Strategie dokumentiert und getestet (laufende Instanzen behalten
  ihre Definition-Version).
- Dokumentation: README-Härtungsabschnitt, ADRs (`docs/adr/0001`–`0005`),
  `docs/crontab.example`.
- End-to-End-Smoke-Test (API + MariaDB + Engine + Runner + Mailer) für den
  Enterprise-Pfad und den Timer-/Runner-Pfad.
- Abhängigkeit `psr/log` ergänzt.

### Added — Phase 8: Angular-Integration
- Library `workflow-client`: Typen (`workflow.models`), `WorkflowService`
  (start/getInstance/currentStep/sendEvent/history), `WorkflowRunnerComponent`
  (standalone, Signals) mit generischem Feld-Rendering aus `ui.fields`, Polling
  solange Status `running` sowie Lade-/Fehler-/Abschluss-Zustaenden.
- Functional `authInterceptor` + Konfigurations-Tokens `WORKFLOW_API_BASE_URL`
  und `WORKFLOW_API_KEY`.
- Demo-App: startet den Onboarding-Workflow und fuehrt ueber den Runner bis
  `completed`; HttpClient mit Auth-Interceptor verdrahtet.
- Tests (Karma/Jasmine, ChromeHeadless): `WorkflowService` mit
  `HttpTestingController`, Interceptor-Verhalten und Runner-Komponente
  (interaktiver Schritt -> Submit -> Abschluss).

### Added — Phase 7: REST-API (Slim)
- HTTP-Layer in `src/Http`: `ApiFactory` (baut die Slim-App), `WorkflowController`
  (typisierte Endpunkte) und `ApiKeyAuthMiddleware` (PSR-15, austauschbar).
- Endpunkte: `POST /workflows/{def}/instances`, `GET /instances/{id}`,
  `GET /instances/{id}/current-step`, `POST /instances/{id}/events`,
  `GET /instances/{id}/history`.
- Einheitliches JSON-Fehlerformat ({ error: { code, message } }); 404/422/409/400/401/500.
- Repository-Lese-Methode `findHistory()` (Interface + Pdo + Fake).
- `api/index.php` nutzt die ApiFactory; `docs/openapi.yaml` ausgearbeitet.
- Endpunkt-Tests ueber Slim-PSR-7-Requests (Happy Paths, 404/422/409, Auth 401)
  und ein Integrationstest fuer `findHistory`.
- Dev-Tooling: `phpstan/phpstan-phpunit` fuer PHPUnit-bewusstes Typ-Narrowing.

### Added — Phase 6: Background-Runner & Trigger (Nebenlaeufigkeit)
- `WorkflowRunner::tick()`: holt faellige Timer-Instanzen ab, laesst sie weiterlaufen
  und prueft datengetriebene Trigger; gibt eine Statistik (woken/started/errors) zurueck.
- `TriggerInterface` und `DataProviderInterface` (Ports); Beispiel `OverdueInvoiceTrigger`
  mit Fake `ArrayDataProvider`.
- **Nebenlaeufigkeit:** `WorkflowRepositoryInterface::claimDueInstances()` holt faellige
  Instanzen mit `SELECT … FOR UPDATE SKIP LOCKED` ab und markiert sie atomar als `running`.
  Parallele Cron-Laeufe verarbeiten dieselbe Instanz nicht doppelt.
- `bin/run-workflows.php` als Cron-Einstieg; `examples/bootstrap.php` (Host-App-Wiring).
- Tests: Runner-Unit (Timer fortgeschrieben, Trigger startet, keine Doppelverarbeitung,
  Fehlerzaehlung) und Integrationstests gegen MariaDB fuer das Locking (gesperrte Zeile
  wird uebersprungen; Status-Flip verhindert Re-Claim).

### Added — Phase 5: Actions & Mailer
- `MailerInterface` (Port) und eingebaute `SendEmailAction` mit
  `{{platzhalter}}`-Interpolation aus dem Instanz-Kontext (fehlende/nicht
  darstellbare Werte werden zu leerem String); Rueckgabe `lastEmailTo` fuer den Merge.
- Test-Double `ArrayMailer`, das versendete Mails im Speicher sammelt.
- Beispielhafte Host-App-Action `MarkVipAction` (leitet einen Wert aus dem Kontext
  ab) als Vorlage fuer eigene Aktionen.
- Tests: korrekte Interpolation und Mailer-Aufruf, unbekannter Action-Key wirft
  Exception, Custom-Action-Ergebnis wird gemergt und in einer Transition genutzt.

### Added — Phase 4: Core-Engine
- `WorkflowEngine` mit `start()`, `advance()` und `handleEvent()` als Zustandsmaschine.
- Step-Typen `automatic` (Action ausfuehren), `interactive` (auf Event warten),
  `timer` (`wake_at` setzen, vom Cron aufgeweckt).
- Transition-Auswahl mit/ohne Event-Bindung, Schleifenschutz (Step-Limit),
  Fehler in Actions fuehren zu Status `failed` mit `last_error`.
- History-Logging an allen Uebergaengen (start/action/wait_event/wait_timer/
  transition/event/complete/error).
- `ActionInterface` (Port) und `ActionRegistry` (von der Engine benoetigt;
  konkrete Actions folgen in Phase 5).
- In-Memory-Fake `InMemoryWorkflowRepository` fuer schnelle Engine-Unit-Tests.
- Unit-Tests: Happy Path bis `completed`, Verzweigung enterprise/standard,
  interaktiver Submit, Bedingung-nicht-erfuellt-bleibt-stehen, Timer mit
  Wiederaufnahme, fehlerhafte Action, Schleifenschutz, Kontext-Merge.

### Added — Phase 3: Expression-Evaluator
- `ExpressionEvaluatorInterface` (Port) mit `evaluate()` und `evaluateValue()`.
- `SymfonyExpressionEvaluator` (sandboxed): Sprache ohne eingebaute Funktionen
  initialisiert — auch `constant()` ist deaktiviert, beliebige PHP-Funktionen sind
  nicht erreichbar.
- Freigegebene Zeitfunktionen `days()`, `hours()`, `minutes()`; zusaetzliche
  Funktionen per Konstruktor (Whitelist) registrierbar.
- Scope mit `context` und `now`; fehlende Kontext-Keys werten zu `null` aus
  (kein Fatal, keine Warnung); ungueltige Ausdruecke werfen `ExpressionException`.
- Beispiel-Ausdruecke und Sandbox-Hinweis im README dokumentiert.

### Added — Phase 2: Persistenz (MariaDB)
- `WorkflowRepositoryInterface` (Port) und `PdoWorkflowRepository`:
  `findDefinition` (neueste aktive Version), `saveInstance` (Insert/Update),
  `findInstance`, `findDueInstances`, `logHistory`.
- Defensive JSON-/Row-Dekodierung (PHPStan level max, keine ungeprueften Casts);
  `id` und `version` der Definition kommen massgeblich aus der DB.
- Integrationstest-Basis `IntegrationTestCase`: legt die Test-DB an und spielt
  `schema.sql` vor jedem Test frisch ein; ueberspringt ohne `WF_DB_DSN`.
- Integrationstests gegen MariaDB: Instanz-Roundtrip inkl. JSON-Kontext,
  `findDueInstances` (nur faellige Timer), History-Schreiben, Versions-Auswahl.
- Skripte: `bin/migrate.php` (Schema anwenden), `bin/seed-definition.php`
  (Definition aus JSON validieren und einspielen).

### Changed
- Phase-0-Platzhalter-Integrationstest entfernt (durch echte Tests ersetzt).

### Added — Phase 1: Domaenenmodell & Definition-Parsing
- Typisierte, readonly Value-Objects: `Transition`, `Step`, `WorkflowDefinition`
  (`src/Definition`) und `WorkflowInstance` (`src/Instance`).
- Strikte `fromArray()`-Parser: validieren Struktur und Typen und werfen bei
  fehlerhafter Definition `InvalidDefinitionException`.
- `DefinitionValidator`: prueft Existenz des Start-Steps, gueltige Transition-Ziele,
  unerreichbare Steps und Zyklen ohne Ausgang (Endzustand muss erreichbar sein).
- Exceptions: `WorkflowException` (Basis) und `InvalidDefinitionException`
  (buendelt mehrere Fehlermeldungen, abrufbar via `errors()`).
- Unit-Tests fuer Happy Path (`examples/onboarding.json`) und alle Fehlerfaelle.

### Added — Phase 0: Setup & Tooling
- Monorepo-Struktur: `backend/`, `frontend/`, `docker/`, `docs/`.
- Backend-Tooling: `composer.json` (PHP >= 8.4, Slim 4, Symfony ExpressionLanguage;
  Dev: PHPUnit 11, PHPStan, php-cs-fixer) mit Scripts `test`, `stan`, `cs`, `cs:fix`.
- PHPUnit-Konfiguration mit getrennten Suites `Unit` / `Integration`.
- PHPStan auf Level `max`, php-cs-fixer auf PSR-12.
- Platzhalter-Smoke-Tests, damit `composer test` gruen laeuft.
- `docker-compose.yml` (MariaDB 11.4 + PHP-CLI) und `.env.example`.
- Angular-Workspace mit Library `workflow-client` und Demo-App.
- Doku-Geruest: `docs/openapi.yaml` (Stub), `docs/adr/`.
- Referenzdaten aus dem Scaffold uebernommen: `schema.sql`, `examples/onboarding.json`.
