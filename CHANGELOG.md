# Changelog

Alle nennenswerten Änderungen an diesem Projekt. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionen nach
[SemVer](https://semver.org/lang/de/). Jede Änderung an einem Port oder an der Form der
HTTP-API ist eine neue Hauptversion (Q-10).

Die Einträge bis 2.3.1 stehen im CHANGELOG auf `main`.

## [Unreleased]

### Neubau 3.0 – Phase 4: REST-Datenquellen und Sicherheit

- **REST-Datenmodelle** (`WorkflowEngine\Rest`, ADR 10): `RestConnection` und
  `RestModelSpec` (Vorgänge Laden/Suchen/Ändern/Anlegen mit Pfad, Trefferliste, Teilmenge,
  Feldpfade) werden zu einem `DataModel` mit Persistenz `rest`. `RestPersistence` liest und
  schreibt verschachteltes JSON; Abläufe sehen keinen Unterschied zur Datenbank.
- **Zugangsdaten:** `SodiumSecretStore` (secretbox, `WF_SECRET_KEY`, alte Schlüssel in
  `WF_SECRET_KEYS_OLD`), `Secret` erscheint nie im Klartext in Logs, Dumps oder JSON;
  `bin/rotate-secret-key.php` für Schlüssel erzeugen und wechseln (F-135).
- **SSRF-Schutz:** `UrlGuard` (nur HTTPS, interne Adressbereiche gesperrt, Allowlist) und
  `SafeHttpClient` (angeheftete IP, geprüfte Weiterleitungen, Zeit- und Grössenlimit,
  F-136, F-144).
- **Fehlerarten:** 404 = nicht gefunden, 429/5xx/Netz = technisch mit Wiederholung, andere
  4xx = dauerhaft; Meldungen ohne Antworttext.
- **Verwaltung:** `DataSourceAdmin` mit allen Prüfungen und Sperren, Verbindungstest,
  Beispielabruf, Trigger-Prüfung und Protokoll (`AuditLogInterface`, F-145). Neue Ports
  `RestConnectionRepositoryInterface`, `RestModelRepositoryInterface`,
  `AdminTriggerRepositoryInterface`, `ModelUsageQueryInterface`, `TriggerSourceInterface`.
- **Admin-Trigger:** `AdminTriggerSource` liefert dem Runner fällige Trigger mit Takt und
  Sperrfrist (`WorkflowRunner::addTriggerSource()`).
- **Ablage:** Migration `3.0.0-002-rest.sql` (`wf_rest_connection`, `wf_rest_model`,
  `wf_trigger`, `wf_audit`), `PdoDataSourceStore`; Integrationstests prüfen, dass keine
  Zugangsdaten im Klartext in der Datenbank stehen.

### Neubau 3.0 – Phase 3: Ablage, Hintergrundlauf, Migration

- **Ablage in MariaDB:** `PdoWorkflowRepository` für Engine, Definitionsverwaltung,
  Instanzliste und Warteschlange des Hintergrundlaufs; `PdoTemplateRepository`.
  Zeitpunkte kommen aus der Uhr, auch `updated_at` und der Verlauf.
- **Neue Ports nach Nutzer:** `DefinitionRepositoryInterface` (speichern, Übersicht mit
  Zählern und Struktur fürs Vorschaubild, Versionen, Entfernen mit Grund,
  Vorlagenverwendung inkl. Seitenvorlagen), `InstanceQueryInterface` (Liste mit Filter,
  Anzahl je Status), `InstanceQueueInterface` (fällige Durchläufe belegen) und
  `TriggerLockInterface` (Sperrfrist).
- **Speichern:** «Entwurf»/«Inaktiv» überschreibt nur, solange kein Durchlauf auf die
  aktuelle Version verweist; sonst entsteht eine neue Version (ADR 3, Nachtrag).
  Abgebrochene Durchläufe zählen nicht als laufend.
- **Hintergrundlauf:** `WorkflowRunner::tick()` weckt fällige Durchläufe, nimmt hängende nach
  der Lease wieder auf und startet Trigger mit Sperrfrist je Datensatz (`PdoTriggerLock`,
  F-143, offener Punkt 2). Fehler betreffen nur ihren Durchlauf. `bin/run-workflows.php`
  lädt den Runner der Host-App über `WF_BOOTSTRAP`.
- **Migration:** `SchemaMigrator` und `bin/migrate.php` legen eine leere Datenbank an oder
  ziehen eine 2.x-Datenbank nach (`migrations/3.0.0-001-v3.sql`). Laufende 2.x-Durchläufe
  und 2.x-Definitionen funktionieren danach unverändert. Leitfaden: `docs/migration-v3.md`.
- **Doppelklick:** Ein bereits angewendetes Ereignis ist auch dann ein No-op, wenn der
  Durchlauf inzwischen beendet ist.
- Integrationstests gegen MariaDB: Repository, `SKIP LOCKED` mit zweiter Verbindung, Lease,
  Vorlagen, Sperrfrist, Migration aus 2.x und der Beispielablauf 9.13 Ende-zu-Ende mit
  Runner.

### Neubau 3.0 – Phase 2: Datenmodelle

- **Datenmodelle** (`WorkflowEngine\Model`, ADR 9) ersetzen die Ports `DataProviderInterface`,
  `DataCatalogInterface`, `DataWriterInterface` und `DataWriteCatalogInterface`. Ein Modell
  beschreibt Felder mit Typ (Text, Zahl, Ja/Nein, Datum, Zeitpunkt) und Zuordnung zur
  Ablage, Freigaben je Feld und Vorgang, eine feste Teilmenge und Regeln (F-110 bis F-128).
  Die Host-App legt Modelle im Code fest: `DataModel::define('antrag', 'Anträge')->…->build()`.
- **DataGateway** mit genau vier Vorgängen (laden, suchen, ändern, anlegen): prüft Freigaben
  und Typen, wendet Regeln an, schreibt nur Geändertes und gibt die Herkunft (Ablauf,
  Version, Durchlauf, Schritt) an die Persistenz weiter. «Nicht gefunden» und «nichts
  geändert» sind Antworten, keine Fehler.
- **Persistenzen** über `PersistenceInterface`: `PdoTablePersistence` für Tabellen der
  Host-App (quotierte Bezeichner, gebundene Werte, Teilmenge bei jedem Vorgang, Typumwandlung
  für MariaDB) und `InMemoryPersistence`. Eigene Persistenzen registriert die Host-App.
- **check_data / write_data** neu auf Datenmodellen, Definitionsformat unverändert (F-114).
  `write_data` schreibt nur Geändertes; `<as>Count` zählt die tatsächlich geänderten Felder.
  Ein Wert, der nur aus einem Platzhalter besteht, behält seinen Typ.
- **Dauerhafte Fehler** (`PermanentFailureInterface`): ungültige Werte, Regelfehler, fehlende
  Freigaben und unbekannte Modelle lassen den Durchlauf sofort fehlschlagen, ohne die
  Wiederholungen nach Q-01.
- **Prüfung beim Speichern:** `DataModelStepRule` (Modelle, Felder, Freigaben, kein «alle
  Felder») und `KnownActionRule` (nur registrierte Aktionen) melden Fehler am Schritt (F-118).
- **Katalog** für Editor und API, abgeleitet aus den Modellen (`DataCatalog`).
- **Trigger:** Port `TriggerInterface` mit `TriggerStart`; `ModelSearchTrigger` startet je
  Treffer einer Suche einen Durchlauf mit zugeordnetem Startkontext.
- Der Beispielablauf `antrag-pruefung` aus 9.13 läuft jetzt vollständig, mit Datencheck und
  Daten schreiben gegen das Modell `antrag` aus 5.6. 157 Unit-Tests, dazu
  Integrationstests der Tabellen-Persistenz gegen MariaDB.

### Neubau 3.0 – Phase 1: Engine-Kern

- **Definitionen:** `DefinitionParser` liest das 2.x-Format unverändert (F-114) und erkennt
  doppelte Schrittnamen schon im JSON, statt sie stillschweigend zu überschreiben.
  Schritt-Arten, Instanz-Status und Verlaufsarten sind Enums mit den Werten aus 2.x.
- **Prüfung:** `DefinitionValidator` liefert einen `ValidationReport` mit Fehlern je
  Schritt und Übergang (Code, Text), alle auf einmal (F-05, F-06). Neu: negative
  Wartezeit. Erweiterungspunkt `StepRuleInterface` für Prüfungen der Host-App (F-118).
- **Engine:** aufgeteilt in `TransitionSelector`, `EventPayloadFilter`, `RetryPolicy`,
  `IdempotencyGuard`, `WakeTimeCalculator` und `BackTargetFinder`. Zeit kommt über
  PSR-20 (`psr/clock`); Einstellungen über `EngineOptions`.
- **Abbrechen:** `WorkflowEngine::cancel()` (offener Punkt 4). Ein abgebrochenes Kind weckt
  den wartenden Eltern-Ablauf mit `subWorkflow.status = cancelled`; ein abgebrochener
  Eltern-Ablauf bricht sein erwartetes Kind mit ab. Der Rücksprung (F-56) bleibt wie in 2.x.
- **Eingabefilter:** Standard ist jetzt `Enforce` (ADR 6, offener Punkt 1).
- **Robuster:** Ereignisse werden nur an wartenden interaktiven Schritten angenommen, nicht
  an Durchläufen, die auf ein Kind warten; ein zu früh geweckter Durchlauf bleibt
  unverändert; eine Mail ohne Empfänger oder mit fehlender Vorlage ist ein technischer
  Fehler mit Wiederholung statt eines stillen Versands.
- **Bedingungen:** nur `context`, `now` (aus der Uhr), `days()`, `hours()`, `minutes()` und
  Funktionen, die die Host-App über `ExpressionFunctionProviderInterface` freigibt (F-100).
- **Platzhalter:** `PlaceholderRenderer` für alle Aktionen, mit Pfaden (`{{subWorkflow.status}}`)
  und `{{now}}`, `{{now.datetime}}`, `{{now.year}}`.
- **Aktionen:** `send_email` und `start_workflow` mit lesbarer Bezeichnung für den Editor;
  `ActionResult` statt magischer Rückgabeschlüssel.
- 93 Unit-Tests mit In-Memory-Fakes und eingefrorener Uhr, darunter der Beispielablauf
  `antrag-pruefung` aus 9.13 auf beiden Pfaden.

### Neubau 3.0 – Phase 0: Fundament

- Neues Gerüst für Engine und Client auf dem Branch `v3` (ADR 8).
- Backend: PHP ≥ 8.4, Slim 4, Symfony ExpressionLanguage/HttpClient 7.4, libsodium;
  PHPUnit 12, PHPStan Level max, php-cs-fixer (PSR-12). `WorkflowEngine\Version` mit
  Engine- und API-Version.
- Integrationstests gegen MariaDB über `WF_DB_DSN`; die Basisklasse räumt die
  Test-Datenbank vor jedem Test vollständig auf.
- Frontend: Angular 22 zoneless, Library `@apollo29/workflow-client` 3.0.0-dev und
  Demo-App; Unit-Tests mit Vitest, End-to-End mit Playwright (breit und 375 px).
- CI für `main` und `v3`: Backend gegen PHP 8.4 und 8.5 mit MariaDB, Frontend mit
  Builds, Vitest und Playwright.
