# Bauplan: Workflow Engine

Dieser Plan ist dafür gedacht, **mit Claude Code** abgearbeitet zu werden — Phase für
Phase, jede Phase als eigene Session, jeweils im **Plan-Modus** starten, von **Tests**
absichern und am Ende **committen**.

> Ausgangspunkt: das vorhandene Scaffold (`workflow-engine.zip`). Es dient als
> Referenz-Design. Claude Code soll es zu einer getesteten, produktionsreifen
> Engine ausbauen — nicht von Null neu erfinden, sondern härten und vervollständigen.

---

## Festgelegte Entscheidungen (nicht mehr zur Diskussion)

- **Backend:** PHP ≥ 8.1, Slim 4 für die REST-API
- **Persistenz:** MariaDB, Definitionen & Kontext als JSON
- **Hintergrundausführung:** Cron + DB-Polling (keine Message-Queue)
- **Integration:** Ports & Adapters — die Engine hängt **nie** von der Host-App ab;
  die Host-App implementiert PHP-Interfaces (DataProvider, Mailer, Action, Trigger)
- **Bedingungen:** Symfony ExpressionLanguage (sandboxed, kein `eval`)
- **Frontend:** Angular (standalone Components, Signals), generisches Rendering
  interaktiver Schritte aus der Definition

---

## Globale Definition of Done

Eine Phase gilt erst als fertig, wenn **alle** Punkte erfüllt sind:

1. Code geschrieben und `declare(strict_types=1)` überall
2. Tests vorhanden und grün (`composer test`)
3. Statische Analyse ohne Fehler (`composer stan` → PHPStan Level max)
4. Code-Style sauber (`composer cs`)
5. Kurzer Eintrag in `CHANGELOG.md`
6. Ein Commit mit aussagekräftiger Message (Conventional Commits)

---

## Wie diese Phasen mit Claude Code abzuarbeiten sind

1. Claude Code **aus dem Repo-Root** starten.
2. Pro Phase eine eigene Session; den jeweiligen Prompt unten einfügen.
3. Immer **Plan-Modus** zuerst: Claude soll den Plan zeigen, du bestätigst, dann ausführen.
4. **Tests sind das Gate** — eine Phase ist nicht fertig, solange Tests rot sind.
5. Am Ende jeder Phase committen, dann erst die nächste Session beginnen.
6. Die `CLAUDE.md` im Repo enthält die dauerhaften Konventionen (siehe separate Datei).

---

## Phase 0 — Projekt-Setup & Tooling

**Ziel:** Reproduzierbares Gerüst mit allen Qualitäts-Werkzeugen.

**Aufgaben**
- Monorepo-Layout: `backend/`, `frontend/`, `docker/`, `docs/`
- `composer.json`: `require` (php, ext-pdo, ext-json, symfony/expression-language,
  slim/slim, slim/psr7) und `require-dev` (phpunit, phpstan, friendsofphp/php-cs-fixer)
- Composer-Scripts: `test`, `stan`, `cs`, `cs:fix`
- PHPUnit-Konfiguration (`phpunit.xml`), Trennung Unit/Integration
- PHPStan auf Level max, `phpstan.neon`
- `docker-compose.yml`: MariaDB + PHP-CLI; `.env.example`
- Angular-Workspace mit Library `workflow-client` + Demo-App

**Akzeptanz**
- `composer install` läuft fehlerfrei
- `composer test` läuft (auch mit 0 Tests grün)
- `composer stan` und `composer cs` laufen
- `docker compose up` startet MariaDB; `ng build` baut die Demo

**Prompt für Claude Code**
> Lies die `CLAUDE.md` und diese `PLAN.md`. Setze **Phase 0** um. Starte im Plan-Modus,
> zeig mir den Plan, dann ausführen. Lege Tooling und Verzeichnisstruktur an, noch keine
> Domänenlogik. Am Ende: alle Composer-Scripts laufen, dann committen.

---

## Phase 1 — Domänenmodell & Definition-Parsing

**Ziel:** Definition/Step/Transition/Instance als typisierte Value-Objects plus
robustes Laden & **Validieren** einer Definition.

**Aufgaben**
- `WorkflowDefinition`, `Step`, `Transition`, `WorkflowInstance` (siehe Scaffold)
- `DefinitionValidator`: prüft Start-Step existiert, alle `to`-Ziele existieren,
  keine unerreichbaren Steps, Erkennung trivialer Zyklen ohne Ausgang
- Klare Exceptions (`InvalidDefinitionException`)

**Tests**
- `examples/onboarding.json` parst korrekt
- defekte Definition (unbekanntes Transition-Ziel) wirft Exception
- Start-Step fehlt → Exception

**Prompt**
> Phase 1 umsetzen: Domänenmodell + `DefinitionValidator`. Schreibe **zuerst die Tests**
> (auch für die Fehlerfälle), dann die Implementierung, bis grün. Plan-Modus zuerst.

---

## Phase 2 — Persistenz (MariaDB)

**Ziel:** `WorkflowRepositoryInterface` + `PdoWorkflowRepository` inklusive
Integrationstests gegen echtes MariaDB.

**Aufgaben**
- `schema.sql` finalisieren (Definition, Instance, History)
- `PdoWorkflowRepository`: save/load Instanz, `findDefinition` (neueste aktive Version),
  `findDueInstances`, `logHistory`
- Einfaches Migrations-/Seed-Skript (Definition aus JSON einspielen)

**Tests (Integration, gegen Docker-MariaDB)**
- Instanz speichern → laden → identischer Zustand (inkl. JSON-Kontext)
- `findDueInstances` liefert nur fällige Timer-Instanzen
- History-Einträge werden geschrieben

**Prompt**
> Phase 2 umsetzen. Integrationstests gegen die MariaDB aus `docker-compose.yml`
> (eigene Testdatenbank, Schema vor jedem Lauf frisch). Plan-Modus zuerst.

---

## Phase 3 — Expression-Evaluator

**Ziel:** Sichere Auswertung der `when`-Bedingungen.

**Aufgaben**
- `ExpressionEvaluatorInterface` (`evaluate`, `evaluateValue`)
- `SymfonyExpressionEvaluator`; Scope mit `context` und `now`
- Optional registrierbare, **freigegebene** Hilfsfunktionen (Whitelist), z. B. `days(n)`
- Dokumentierte Beispiel-Ausdrücke

**Tests**
- Vergleiche, Boolesche Verknüpfungen, Datumsvergleiche
- Ausdruck mit unbekannter Variable → kontrolliertes Verhalten (keine Fatal-Error)
- kein Zugriff auf beliebige PHP-Funktionen

**Prompt**
> Phase 3 umsetzen. Fokus auf Sicherheit: nur freigegebene Funktionen, kein `eval`.
> Tests zuerst. Plan-Modus zuerst.

---

## Phase 4 — Core-Engine (start / advance / handleEvent)

**Ziel:** Die eigentliche Zustandsmaschine — Kern des Systems.

**Aufgaben**
- `start()`, `advance()`, `handleEvent()` (siehe Scaffold-Logik)
- Step-Typen: `automatic`, `interactive`, `timer`
- Transition-Auswahl (mit/ohne Event-Bindung), Schleifenschutz, Fehler → `failed`
- History-Logging an allen Übergängen

**Tests (Unit, mit In-Memory-Repository-Fake)**
- Happy Path Onboarding bis `completed`
- Verzweigung (`plan == enterprise` vs. Standard)
- Interaktiver Schritt: `handleEvent('submit', …)` führt weiter
- Bedingung nicht erfüllt → bleibt im selben Schritt
- Timer-Schritt setzt `wake_at`, `advance` nach Fälligkeit läuft weiter
- fehlerhafte Action → Status `failed`, `last_error` gesetzt

**Prompt**
> Phase 4 umsetzen: die Engine. Nutze einen In-Memory-Fake des Repositories für schnelle
> Unit-Tests; decke alle oben genannten Fälle ab. Tests zuerst. Plan-Modus zuerst.

---

## Phase 5 — Actions & Mailer

**Ziel:** Erweiterbare Aktionen plus eingebauter E-Mail-Versand.

**Aufgaben**
- `ActionInterface`, `ActionRegistry`
- `SendEmailAction` mit `{{platzhalter}}`-Interpolation
- `MailerInterface` + Test-Doubles (`ArrayMailer`, der versendete Mails sammelt)
- Beispiel einer Host-App-Action (Demo), die den Kontext verändert

**Tests**
- `send_email`: korrekte Interpolation, Mailer wird mit erwarteten Werten aufgerufen
- unbekannter Action-Key → Exception
- Custom Action mergt Rückgabewerte in den Kontext

**Prompt**
> Phase 5 umsetzen. `ArrayMailer` für Tests. Tests zuerst. Plan-Modus zuerst.

---

## Phase 6 — Background-Runner & Trigger (inkl. Nebenläufigkeit)

**Ziel:** Cron-getriebene Hintergrundausführung, die auch bei mehreren Workern korrekt ist.

**Aufgaben**
- `WorkflowRunner::tick()`: fällige Timer aufwecken, Trigger pollen
- `TriggerInterface` + Beispiel-Trigger über den `DataProvider`
- **Nebenläufigkeit:** Instanzen beim Abholen sperren
  (`SELECT … FOR UPDATE SKIP LOCKED` in einer Transaktion **oder** Versionsspalte
  mit Optimistic Locking) — zwei parallele Crons dürfen dieselbe Instanz nicht doppelt verarbeiten
- `bin/run-workflows.php` als Cron-Einstieg

**Tests**
- fällige Timer-Instanz wird fortgeschrieben
- Trigger startet neue Instanz aus Beispieldaten
- Nebenläufigkeit: zwei `tick()`-Läufe verarbeiten dieselbe Instanz **nicht** doppelt

**Prompt**
> Phase 6 umsetzen, mit besonderem Fokus auf das Locking. Schreibe einen Test, der zwei
> gleichzeitige `tick()`-Läufe simuliert und Doppelverarbeitung ausschließt. Plan-Modus zuerst.

---

## Phase 7 — REST-API (Slim)

**Ziel:** Saubere HTTP-Schicht mit Validierung, Fehlerbehandlung und Auth-Haken.

**Aufgaben**
- DI-Container-Setup, Routen aus dem Scaffold übernehmen/härten
- Request-Validierung, einheitliches JSON-Fehlerformat
- Auth-Middleware (API-Key/Bearer als Platzhalter, austauschbar)
- Endpunkt `GET /instances/{id}/history`
- OpenAPI-Spezifikation in `docs/openapi.yaml`

**Tests**
- Endpunkt-Tests über Slims Test-Request (start, current-step, events, history)
- 404 bei unbekannter Instanz, 422 bei fehlendem Event

**Prompt**
> Phase 7 umsetzen. Endpunkt-Tests mit dem PSR-7-Test-Request. Auth als austauschbare
> Middleware. Plan-Modus zuerst.

---

## Phase 8 — Angular-Integration

**Ziel:** Wiederverwendbare Client-Library + Demo, die einen Workflow durchspielt.

**Aufgaben**
- Library `workflow-client`: `models`, `WorkflowService`, `WorkflowRunnerComponent`
- Generisches Feld-Rendering aus `ui.fields`; Polling, solange Status `running`
- Lade-/Fehler-/Abschluss-Zustände
- Demo-App, die `onboarding` startet und bis `completed` führt
- HTTP-Interceptor für Auth-Header

**Akzeptanz**
- Demo durchläuft den interaktiven Schritt und erreicht `completed`
- Unit-Tests für `WorkflowService` (HttpTestingController)

**Prompt**
> Phase 8 umsetzen. Standalone Components + Signals. Service-Tests mit
> `HttpTestingController`. Demo, die den Onboarding-Workflow real durchspielt. Plan-Modus zuerst.

---

## Phase 9 — Härtung & Dokumentation

**Ziel:** Produktionsreife.

**Aufgaben**
- Retry/Backoff für fehlgeschlagene Actions (statt direktem `failed`), mit Obergrenze
- Idempotenz beim Event-Handling (doppelte Submits abfangen)
- Strukturiertes Logging/Metriken im Runner
- Versionierung: Strategie, wie laufende Instanzen bei neuer Definition-Version weiterlaufen
- `README.md`, kurze ADRs in `docs/adr/`, Beispiel-Crontab
- End-to-End-Smoke-Test (API + DB + Runner zusammen)

**Prompt**
> Phase 9 umsetzen. Beginne mit Retry/Backoff und Idempotenz inkl. Tests, dann Doku und
> ein E2E-Smoke-Test. Plan-Modus zuerst.

---

## Reihenfolge-Logik (warum so)

Modell → Persistenz → Evaluator sind die Bausteine, auf denen die Engine (Phase 4)
aufsetzt. Erst danach machen Actions, Runner und API Sinn. Das Frontend kommt spät,
weil es eine stabile API braucht. Härtung zuletzt, wenn die Funktionalität steht.
Jede Phase ist eigenständig testbar — ideal für getrennte Claude-Code-Sessions.
