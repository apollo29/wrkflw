# Changelog

Alle nennenswerten Aenderungen an diesem Projekt werden hier dokumentiert.
Format orientiert an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased]

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
