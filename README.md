# Workflow Engine

Eingebettete Workflow-Engine als PHP-Library, mit Slim-REST-API, MariaDB-Persistenz
(JSON) und Angular-Client. Hintergrundausführung über Cron + DB-Polling, Integration in
die Host-App über klar definierte PHP-Interfaces (Dependency Inversion).

## Kernidee

Eine **Definition** (Template, versioniert) beschreibt einen gerichteten Graphen aus
**Steps** und **Transitions**. Eine **Instanz** ist ein laufender Durchlauf mit eigenem
Zustand (aktueller Step, Kontext, Status). Die Trennung ist bewusst: laufende Instanzen
bleiben stabil, auch wenn die Definition weiterentwickelt wird.

Engine-Step-Typen:

| Typ | Verhalten |
|-----|-----------|
| `automatic` | läuft im Hintergrund durch (führt eine Action aus, wertet dann Transitionen aus) |
| `interactive` | hält an und wartet auf ein Frontend-Event (führt den Benutzer) |
| `timer` | wartet bis zu einem Zeitpunkt; der Cron-Runner weckt die Instanz auf |

Damit deckt dasselbe Modell Hintergrund-Abläufe **und** benutzergeführte Abläufe ab.
Der visuelle Builder bietet darüber hinaus zwei **Schritt-Karten als Komfort** an, die
intern automatische Schritte mit einer eingebauten Action sind: **Workflow**
(`start_workflow`) und **Datencheck** (`check_data`).

## Eingebaute Actions

Automatische Schritte führen eine Action aus (Schlüssel in der `ActionRegistry`).
Mitgeliefert:

| Action | Zweck | Wichtige Config |
|--------|-------|-----------------|
| `send_email` | E-Mail versenden | `to`, `from` (leer = Standard-Mailbox), `cc`, `bcc`, `subject`, `body` (HTML); optional `templateId` (eine Vorlage überschreibt Betreff + Inhalt) |
| `start_workflow` | einen anderen Workflow **verknüpfen** | `workflowId`; `waitForCompletion` (true = Eltern wartet auf das Kind, dann Ergebnis unter `subWorkflow`; false = feuer-und-vergiss, ID unter `startedWorkflow`) |
| `check_data` | einen **Wert aus einer Tabelle** lesen | `entity`, `id` (mit `{{platzhalter}}`), `field`, `as` (Kontext-Key); schreibt `<as>` und `<as>Found` — der Vergleich läuft danach über die Übergangs-Bedingung |

Eigene Actions implementieren `ActionInterface`; für Editor-Felder zusätzlich optional
`ConfigurableActionInterface` (`configSchema()`), dann erscheinen sie im Action-Katalog
(`GET /actions`).

## Architektur (Ports & Adapters)

Die Engine kennt die Host-App nicht. Sie definiert **Ports** (Interfaces), die die
Host-App als **Adapter** implementiert:

- `DataProviderInterface` – Zugriff auf die Datenstruktur der App (Bedingungen, Trigger, `check_data`)
- `DataCatalogInterface` – Katalog abfragbarer Tabellen/Felder (für die Editor-Dropdowns des Datencheck)
- `MailerInterface` – E-Mail-Versand; erhält ein `EmailMessage`-Wertobjekt (to/from/cc/bcc/subject/body/vars)
- `ActionInterface` (+ optional `ConfigurableActionInterface`) – eigene Aktionen
- `ExpressionEvaluatorInterface` – Auswertung der Bedingungen (Default: Symfony ExpressionLanguage)
- `WorkflowRepositoryInterface` – Persistenz von Definitionen/Instanzen (Default: `PdoWorkflowRepository`)
- `TemplateRepositoryInterface` – wiederverwendbare Vorlagen (Default: `PdoTemplateRepository`, Tabelle `wf_template`)
- `WorkflowStarterInterface` – von der Engine implementiert; erlaubt `start_workflow`, weitere Workflows zu starten
- `TriggerInterface` – datengetriebene Start-Trigger, vom Cron-Runner gepollt

```
                ┌─────────────────────────────────────────┐
   API / Cron   │              WorkflowEngine               │
   ────────────▶│  start() · advance() · handleEvent()      │
                └───────┬───────────────┬──────────────┬────┘
                        │               │              │
                  Repository       ActionRegistry   Evaluator
                   (MariaDB)      (send_email,        (Symfony)
                                   start_workflow,
                                   check_data, …)
                        ▲               ▲
                        └─ Adapter der Host-App (DataProvider, Mailer, Katalog) ┘
```

## Workflow-Status (Lebenszyklus)

Jede Definition hat einen Status: **aktiv**, **inaktiv** oder **entwurf**.

- **aktiv:** Speichern legt eine **neue Version** an (wird ausgeliefert/getriggert).
- **inaktiv / entwurf:** Speichern legt **keine neue Version** an (die aktuelle Version
  wird in-place überschrieben) und die Definition wird **nicht** gestartet/getriggert.

Technisch markiert `active=1` die aktuelle (editierbare) Version; ausgeliefert wird nur
`active=1 AND status='active'`. Laufende Instanzen sind an ihre Version gebunden und
bleiben unberührt.

## Vorlagen (Templates)

Wiederverwendbare Vorlagen (Tabelle `wf_template`) mit einem Typ:

- **`email`** – Betreff + HTML-Body; von `send_email` über `templateId` referenziert.
- **`page`** – HTML-Seiteninhalt; von einem **interaktiven** Schritt über `ui.templateId`
  referenziert und vom Runner oberhalb der Felder gerendert.

Beide werden im selben WYSIWYG-Editor (`wf-html-editor`) mit `{{platzhalter}}` aus dem
Kontext gepflegt. API: `GET /templates?type=email|page`, `GET/POST/DELETE /templates/{id}`,
`GET /templates/{id}/usage` (welche Schritte referenzieren die Vorlage).

## Bedingungen (`when`)

Transitionen nutzen Ausdrücke der Symfony ExpressionLanguage. Der Scope enthält
`context` (die Instanz-Variablen) und `now` (Unix-Timestamp):

```
context['status'] == 'approved'
context['amount'] > 1000 and context['vip']
context['orderStatus'] == 'paid'        # z. B. Ergebnis eines check_data-Schritts
context['lastSeen'] < now - days(30)
```

**Sandbox:** Der `SymfonyExpressionEvaluator` läuft ohne eingebaute Funktionen – auch das
standardmäßige `constant()` ist deaktiviert, beliebige PHP-Funktionen sind nicht
erreichbar. Freigegeben sind nur die Zeitfunktionen `days(n)`, `hours(n)`, `minutes(n)`
(liefern Sekunden) sowie Funktionen, die die Host-App ausdrücklich übergibt
(`new SymfonyExpressionEvaluator([$ownFunction])`). Fehlende Kontext-Keys werten zu `null`
aus (kein Fehler); ungültige Ausdrücke werfen eine `ExpressionException` statt eines Fatals.

## Setup

```bash
cd backend
composer install

# MariaDB starten (Docker) — Port 3307, damit ein lokales MySQL auf 3306 nicht kollidiert:
docker compose -f ../docker/docker-compose.yml up -d

# Schema + inkrementelle Migrationen anwenden (idempotent):
DB_HOST=127.0.0.1 DB_PORT=3307 DB_USER=root DB_PASS=root DB_NAME=workflow php bin/migrate.php
```

`schema.sql` läuft bei einem frischen DB-Volume automatisch als Docker-Init-Skript;
`bin/migrate.php` wendet danach `migrations/*.sql` an und zieht so **bestehende**
Datenbanken (fehlende Tabellen/Spalten) nach.

Beispiele einspielen (Definitionen & Vorlagen):

```bash
php bin/seed-template.php   examples/welcome-page.template.json   # 'page'-Vorlage
php bin/seed-definition.php examples/onboarding.json
php bin/seed-definition.php examples/newsletter.json              # Seitenvorlage + verknüpfter Workflow
php bin/seed-demo-data.php                                        # Test-Tabellen orders/users/invoices
php bin/seed-definition.php examples/order-check.json             # Datencheck-Beispiel
```

Cron (jede Minute) für Timer & datengetriebene Trigger:

```
* * * * * php /pfad/backend/bin/run-workflows.php >> /var/log/wf.log 2>&1
```

## Demo starten

Den kompletten Stack (MariaDB → Migrationen/Seed → REST-API → Angular-Demo) startet ein
Skript:

```bash
bash scripts/demo.sh
# -> http://localhost:4200  (API über Dev-Proxy auf http://127.0.0.1:8080)
```

Voraussetzungen: Docker, PHP, Node. Strg-C stoppt API und MariaDB wieder. Die Demo hat
drei Tabs:

- **Runner** – Workflow aus der Liste wählen, Start-Kontext (JSON) bearbeiten und starten.
  Beispiele: `onboarding`, `newsletter` (Seitenvorlage + verknüpfter Onboarding-Workflow),
  `ordercheck` (Datencheck über die `orders`-Tabelle — `orderId=1` ist *paid*, `orderId=2`
  *pending*).
- **Editor** – visueller Builder: Schritt-Karten (Automatisch/Interaktiv/Timer/Workflow/
  Datencheck), Bedingungs-Assistent, Status (Aktiv/Entwurf/Inaktiv), Visuell/JSON-Umschalter.
- **Templates** – E-Mail- und Seiten-Vorlagen verwalten (WYSIWYG, Platzhalter,
  Verwendungs-Anzeige, Löschen).

## API-Überblick

| Methode & Pfad | Zweck |
|----------------|-------|
| `POST /workflows/{def}/instances` | Instanz starten (nur aktive Definitionen) |
| `GET /instances/{id}` · `/current-step` · `/history` | Zustand / aktueller Schritt / Verlauf |
| `POST /instances/{id}/events` | Event senden (Header `Idempotency-Key` optional) |
| `GET /workflows` · `GET /workflows/{def}` · `POST /workflows/{def}` | Definitionen listen/lesen/speichern (mit `status`) |
| `GET /actions` | Action-Katalog inkl. Config-Schema |
| `GET /templates` · `GET/POST/DELETE /templates/{id}` · `GET /templates/{id}/usage` | Vorlagen |
| `GET /data-catalog` | abfragbare Tabellen/Felder für den Datencheck |

## Frontend (Angular-Client)

Die Library `@apollo29/workflow-client` (standalone Components, Signals) liefert:

- `WorkflowService` – schmaler API-Client (`start`, `currentStep`, `sendEvent`,
  `listDefinitions/getDefinition/saveDefinition`, `listActions`, Templates, `dataCatalog`)
- `wf-runner` (`WorkflowRunnerComponent`) – rendert interaktive Steps generisch aus
  `ui.fields`, zeigt eine referenzierte Seitenvorlage, pollt, solange der Workflow läuft
- `wf-builder` (`WorkflowBuilderComponent`) – der visuelle No-Code-Builder
- `wf-template-manager` (`WorkflowTemplateManagerComponent`) – Vorlagenverwaltung
- `wf-html-editor` (`HtmlEditorComponent`) – WYSIWYG-Editor (TipTap) mit Platzhaltern

```html
<wf-runner [instanceId]="id" />
```

Der interaktive Step liefert seine UI-Beschreibung (`ui.fields`, `events`, optional
`ui.templateId`) selbst mit – das Frontend muss die einzelnen Workflows nicht kennen.

## Als Library einbinden

`examples/bootstrap.php` zeigt das vollständige Wiring: die Host-App implementiert die
Ports (`MailerInterface`, `DataProviderInterface`/`DataCatalogInterface`, …), registriert
die eingebauten und eigene Actions und setzt `WorkflowEngine` + `WorkflowRunner` in einem
PSR-11-Container (php-di) zusammen; `api/index.php` baut daraus die Slim-App
(`ApiFactory::createFromContainer`).

### PHP-Engine (Composer)

Paket `apollo29/workflow-engine` (Namespace `WorkflowEngine\` → `backend/src`). Per Git/VCS
in der `composer.json` des Zielprojekts:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/apollo29/wrkflw" }],
  "require": { "apollo29/workflow-engine": "^1.14" }
}
```

Auf Packagist veröffentlicht genügt `composer require apollo29/workflow-engine`. Die Tags
`vX.Y.Z` sind die Versionen. Schema: `backend/schema.sql` (+ `backend/migrations/`);
Wiring-Vorlage: `backend/examples/bootstrap.php`.

> Hinweis: GitHub Packages unterstützt **kein** Composer/PHP — die Engine wird per VCS
> oder Packagist bezogen.

### Angular-Client (npm, GitHub Packages)

Paket `@apollo29/workflow-client` (Angular ≥ 19.2), veröffentlicht in der **GitHub-
Packages npm-Registry**. Release über einen Tag `client-vX.Y.Z` — der Workflow
`publish-npm` baut und publisht via `GITHUB_TOKEN`.

Im Zielprojekt eine `.npmrc` anlegen (Scope auf GitHub Packages routen):

```
@apollo29:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${GITHUB_TOKEN}
```

`GITHUB_TOKEN` ist ein Personal Access Token mit `read:packages`. Dann:

```bash
npm install @apollo29/workflow-client
```

Ohne Registry (rein lokal): `cd frontend && ng build workflow-client` und im Zielprojekt
`npm install /pfad/zu/frontend/dist/workflow-client`.

## Nebenläufigkeit (mehrere Cron-Worker)

Der `WorkflowRunner` holt fällige Timer-Instanzen über
`WorkflowRepositoryInterface::claimDueInstances()` ab. Die PDO-Implementierung sperrt die
Zeilen mit `SELECT … FOR UPDATE SKIP LOCKED` in einer Transaktion und markiert sie atomar
als `running`. Dadurch verarbeiten parallele Cron-Läufe dieselbe Instanz nicht doppelt
(benötigt InnoDB; `SKIP LOCKED` ab MariaDB 10.6).

## Härtung: Retry, Idempotenz, Versionierung

**Retry & Backoff.** Wirft die Action eines automatischen Schritts eine Exception, geht die
Instanz nicht sofort auf `failed`, sondern wird als `waiting_timer` mit exponentiell
wachsender Verzögerung (`baseRetryDelaySeconds * 2^(versuch-1)`) erneut eingeplant – bis zu
`maxAttempts` Versuchen (Konstruktor-Parameter von `WorkflowEngine`, Default 3 / 60 s).
Erst danach `failed`. Der Zähler `attempts` wird bei jedem erfolgreichen Schrittwechsel
zurückgesetzt.

**Idempotenz.** `handleEvent()` akzeptiert einen optionalen Idempotenz-Key; über die API
wird dieser aus dem Header `Idempotency-Key` gelesen. Ein bereits angewendeter Key ist ein
No-op (History-Eintrag `event_duplicate`). Angewendete Keys werden im Kontext unter dem
reservierten Schlüssel `__appliedEventIds` gehalten.

**Versionierung.** Eine Instanz speichert `definition_ver` und wird über
`findDefinition(id, version)` immer mit **genau dieser** Version ausgeführt. Nur **neue**
Instanzen nutzen die neueste aktive Version (`start()`); laufende Instanzen laufen stabil
auf ihrer Version weiter.

**Logging.** Der `WorkflowRunner` akzeptiert einen optionalen PSR-3-Logger und schreibt
strukturierte Einträge (`workflow.tick` mit Statistik, `workflow.advance_failed`,
`workflow.trigger_failed`).

## Tests & Qualität

```bash
cd backend
composer test    # PHPUnit (Unit + Integration gegen MariaDB via WF_DB_DSN)
composer stan    # PHPStan Level max
composer cs      # php-cs-fixer (PSR-12)

cd ../frontend
ng build workflow-client && ng build demo
ng test workflow-client --watch=false --browsers=ChromeHeadlessCI --karma-config=karma.conf.js
```

Integrationstests brauchen eine MariaDB (siehe `docker/`); die Verbindung kommt aus
`WF_DB_DSN` / `WF_DB_USER` / `WF_DB_PASS`. Ohne gesetzte DSN werden sie übersprungen.
