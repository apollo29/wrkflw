# Changelog

Alle nennenswerten Änderungen an diesem Projekt. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionen nach
[SemVer](https://semver.org/lang/de/). Jede Änderung an einem Port oder an der Form der
HTTP-API ist eine neue Hauptversion (Q-10).

Die Einträge bis 2.3.1 stehen im CHANGELOG auf `main`.

## [Unreleased]

### Neubau 3.0 – Phase 10: Vorlagen und HTML-Editor

- **Reiter «Vorlagen»** (9.11, E-40 bis E-43):
  - Liste je Art (E-Mail/Seite), Auswahl in der Adresse, «+ Vorlage»
  - ID (nach dem ersten Speichern fest), Name, Betreff, Inhalt mit Vorschau
  - Verwendung «Ablaufname · vN · Schritt» mit Link in den Editor
  - Löschen mit Rückfrage samt Anzahl Verwendungen; 409 `in_use` zeigt den Grund
  - Pill und Rückfrage bei ungespeicherten Änderungen, auch beim Wechsel der Vorlage (E-08)
- **HTML-Editor** (E-44 bis E-48, F-70 bis F-72):
  - Werkzeuggruppen Text, Struktur, Ausrichtung, Bearbeiten, Platzhalter, Einfügen, Ansicht
  - E-Mail-Tabellen (3×3 mit Kopfzeile, Zeile/Spalte ±): Inline-Rahmen und -Abstände,
    Breiten in Prozent; «2 Spalten» als Layout-Tabelle
  - Bild-URL inline, Bild-Upload über `provideWorkflowAdmin({ uploadImage })`; keine Daten-URLs
  - eigener Platzhalter, Vorschläge aus den verwendenden Abläufen
  - HTML-Quelltext und Vorschau mit Beispielwerten im abgeschotteten `iframe`
- **Sicherheit (Engine):** `send_email` escapt Kontextwerte im HTML-Inhalt; der Betreff
  bleibt Klartext. `PlaceholderRenderer::render()` hat dafür die Option `html`.
- **Demo:** Vorlagen speichern, löschen und Verwendung im Ersatz-Backend; neue Vorlage
  `antrag-ok` mit Tabelle und Button für den Schritt `bestaetigung`; Demo-Bildupload.

### Neubau 3.0 – Phase 9: Runner

- **`<wf-workflow-runner [instanceId]>`** stellt Durchläufe generisch dar (F-50, F-85):
  - Titel, Beschreibung und Seitenvorlage; Platzhalter werden escaped
  - Text-, Ja/Nein-, Anzeige- und Datei-Felder, vorbelegt aus dem Kontext
  - ein Knopf je Ereignis mit seiner Beschriftung (F-52)
  - «Ein Schritt zurück», nur wenn möglich (F-56)
  - Warte-, Lauf-, End- und Fehlerzustände in Klartext (F-22, F-23)
  - Ausgänge `finished` und `stepChange`
- **Doppelklicksicher (Q-04):** jedes Absenden mit `Idempotency-Key`; nach einem Netzfehler
  sendet «Erneut versuchen» denselben Schlüssel.
- **Datei-Upload über die Host-App** (F-54): `provideWorkflowAdmin({ uploadFile })`.
  - Eine Ablehnung erscheint mit Grund am Feld, und der Schritt bleibt offen.
  - Bei Erfolg geht der zurückgegebene Wert mit dem Ereignis.
  - Ohne Funktion erscheint ein Hinweis.
- **Admin:** «Bearbeiten» im Reiter «Instanzen» öffnet wartende Durchläufe unter
  `instanzen/:id`. Editor, Instanzen und Runner werden erst bei Bedarf geladen.
- **Demo:** Seite «Durchspielen» (Workflow starten und durchspielen). Das
  Ersatz-Backend startet Durchläufe, verarbeitet Ereignisse samt Idempotenz und «zurück».
  Der Demo-Upload nimmt PDF bis 5 MB an.

### Neubau 3.0 – Phase 8: Übersicht und Instanzen

- **Übersicht «Workflows»** (9.2, E-60 bis E-68):
  - eine Zeile je Workflow mit Vorschaubild (gleiche Berechnung wie die Ablauf-Vorschau,
    verkleinert statt abgeschnitten), Name, ID, Version, Status, Schritten, Durchläufen und
    Änderungsdatum
  - Suche und Statusfilter; Zeile öffnet den Editor, auch per Tastatur
  - ältere Versionen im Aufklapper: «Ansehen» öffnet schreibgeschützt, «×» entfernt nach
    Rückfrage, gesperrte Versionen mit Grund
  - leere Zustände; auf schmalen Flächen Karten mit dem Bild oben
- **Instanzen:**
  - Statusfilter (in der Adresse), Tabelle mit Workflow und Version, Subjekt, Schritt in
    der Farbe seiner Art, Status mit Fehlergrund, nächstem Lauf und Aktualisierung
  - «Verlauf» klappt den Verlauf in Sätzen auf, nur mit Feldnamen (F-90, F-91)
  - **Durchlauf abbrechen** mit Rückfrage (offener Punkt 4); «Weitere laden» in Schritten
    von 50
- **API:** `GET /workflows` liefert `olderRunning`, die laufenden Durchläufe auf älteren
  Versionen («1 laufend auf v2», E-66).
- **Demo:** Beispieldurchläufe nach 9.13 mit Verlauf, Fehlschlag, Timer und Abbrechen;
  `antrag-pruefung` in drei Versionen, `mahnung` als Entwurf.
- **Korrekturen:**
  - Linkfarbe der Grundstile überschreibt keine Knöpfe mehr.
  - Breite Tabellen scrollen in ihrer Zone statt der ganzen Seite.

### Neubau 3.0 – Phase 7: Editor

- **Editor als eigene Seite** (`workflows/neu`, `workflows/:id`, `workflows/:id/v/:version`
  schreibgeschützt):
  - Kopfleiste mit Status und Ansicht, Meldungen, Ablauf-Vorschau, Seitenspalte und Detail
    (3/9 von 12 Spalten), «Schritt hinzufügen» mit sechs Karten
  - Store mit Signals (`EditorStore`) statt mutierter Objekte
- **Ablauf-Vorschau:** Verzweigungen mit Spuren, Zusammenführung und einspurigem Rest
  (Port aus 2.x); Knopftext statt «SONST», wenn ein Ausgang nur an einem Knopf hängt;
  einklappbar mit Speicherung.
- **Schritte:**
  - Formulare je Art: Interaktiv mit Eingabefeldern und Datei-Prüfung, Automatisch mit
    Aktionsfeldern nach Typ, Timer, Workflow, Datencheck, Daten schreiben mit Bedingung
    «Nur wenn / Sonst»
  - Umbenennen zieht Übergänge und Start-Schritt mit; doppelte Namen sind gesperrt.
  - Löschen schliesst die Lücke und lässt sich rückgängig machen.
  - Schrittliste per Tastatur bedienbar.
- **Übergänge:** Sonst-Zeile und Bedingungskarte; «Bedingung…» öffnet den Assistenten
  (sechs Vergleiche, Ausdruck sichtbar), «Erweitert» für eigene Ausdrücke, Knopftext und
  Vorschau der Knöpfe an interaktiven Schritten.
- **Laufende Prüfung:** lokal sofort und beim Server (`/workflows/validate`, entprellt);
  Hinweise erscheinen am Schritt, in der Liste und im roten Kasten. «Aktiv» speichert nur
  ohne Hinweise, Entwurf und Inaktiv auch mit.
- **Weitere Funktionen:**
  - JSON-Ansicht mit Übernahme; ungültiges JSON bleibt mit Meldung stehen.
  - Speichern in der JSON-Ansicht speichert den Text.
  - Schutz vor Verlust beim Verlassen.
- **HTML-Editor** (TipTap 3) für E-Mail-Inhalte:
  - Formatierung, Listen, Link und Knopf mit Adresse im Editor statt Browser-Dialog
  - Platzhalter aus den Kontext-Variablen
  - wird erst bei Bedarf geladen (`@defer`)
- **Definition wie v3 sie liest:** Knopftext am Übergang (`label`), Seitenvorlage in
  `ui.template`, kein `ui.events`; die 2.x-Schlüssel `ui.eventLabels` und `ui.templateId`
  werden weiter gelesen.
- **Demo:** Ersatz-Backend im Browser mit dem Beispiel `antrag-pruefung`; E2E-Tests für den
  Editor.

### Neubau 3.0 – Phase 6: Client-Fundament

- **Gestaltung:** `styles/wf.css` mit Tokens `--wfb-*` nach dem Design-Canvas (hell und
  `data-theme="dunkel"`), 12er-Raster mit Container Queries (breit, mittel, schmal mit
  44-px-Touch-Zielen) und Grundstilen. Wird als Asset der Library ausgeliefert.
- **Bausteine:** `wf-icon` (sechs Schritt-Arten und Werkzeug-Icons), `button[wfButton]`,
  `wf-segment` (Radiogruppe mit Pfeiltasten), `wf-pill` mit Statusfarben, `wf-field`
  (verknüpft Label, Hinweis und Fehler per ARIA), `wf-banner`, Rückfrage-Dialog auf
  `<dialog>`, Meldungen mit «Rückgängig» (`WfToastService`), Darstellung hell/dunkel/System.
- **Texte:** Katalog `MESSAGES_DE` mit `WfI18n`/`wfT`; die Host-App ersetzt Texte über
  `provideWorkflowAdmin({ messages })`. Ein Test sucht feste Texte in Templates.
- **Konfiguration und API:** `provideWorkflowAdmin({ apiBaseUrl, apiKey | authToken,
  messages, theme })`; `WorkflowApi` für alle Routen der API v3 mit Fehlern als
  `WfApiError`; Listen als `httpResource` (`WorkflowResources`). Der Anmelde-Header geht
  nur noch an `apiBaseUrl` (der Interceptor aus 2.x schickte ihn an jede Adresse).
- **Shell und Routen:** `workflowAdminRoutes` mit den Reitern Workflows, Instanzen, Vorlagen
  und Datenquellen (vorerst Platzhalter). Schutz vor Verlust ungespeicherter Änderungen
  (E-08): `wfUnsavedChangesGuard` mit «Verwerfen / Im Editor bleiben / Speichern und
  verlassen» und `wfBeforeUnload()`.
- **Demo:** Admin-Oberfläche unter `/admin`, alle Bausteine unter `/bausteine`; E2E für
  Reiter, Tastatur, Dialog, Dunkelmodus und 375 px.

### Neubau 3.0 – Phase 5: HTTP-API

- **Routen** (`WorkflowEngine\Http`, ADR 11):
  - aus 2.x übernommen: starten, anzeigen, aktueller Schritt, Ereignis, zurück, Verlauf,
    Definitionen, Aktionen, Vorlagen
  - neu: `GET /instances` mit Filtern und Anzahl je Status, `POST /instances/{id}/cancel`,
    `GET /workflows` mit Struktur und Zählern, `GET /workflows/{id}/versions[/{v}]`,
    `POST /workflows/validate`, `GET /data-models` (ersetzt `/data-catalog`),
    `GET /upload-handlers` (endlich registriert, neuer Port `UploadHandlerCatalogInterface`)
  - `/data-sources/{connections,models,triggers,audit}` mit Test, Beispielabruf,
    «Jetzt prüfen» und Verwendung; nur mit Admin-Recht
- **Zugriff über einen Port:** `AccessResolverInterface` liefert Person und Admin-Recht
  (F-104); mitgeliefert `ApiKeyAccessResolver` (`WF_API_KEY`) und `OpenAccessResolver`
  (nur Entwicklung). Ohne Zugriff 401, Datenquellen ohne Admin-Recht 403.
- **Ein Fehlerformat** `{error: {code, message, details?}}` überall, auch beim Löschen
  einer Version; Übersetzung der Ausnahmen an einer Stelle (`ErrorMapper`), 500 ohne
  Einzelheiten.
- **Speichern:** «Aktiv» nur fehlerfrei; Entwurf und Inaktiv mit Prüffehlern möglich
  (`validation` in der Antwort).
- **Zugangsdaten** erscheinen in keiner Antwort; Tests prüfen alle Datenquellen-Antworten
  und das Protokoll.
- `api/index.php` lädt die Verdrahtung der Host-App über `WF_API_BOOTSTRAP`.
- `docs/openapi.yaml` v3 (OpenAPI 3.1); ein Test gleicht sie mit den Routen ab.
- `InMemoryWorkflowRepository` setzt jetzt auch Definitionsverwaltung und Instanzliste um
  (gleiche Regeln wie die PDO-Ablage).

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
