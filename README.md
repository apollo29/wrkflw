# Workflow Engine

Eingebettete Workflow-Engine als PHP-Library, mit Slim-REST-API, MariaDB-Persistenz
(JSON) und Angular-Frontend-Integration. Hintergrundausführung über Cron + DB-Polling,
Integration in die Host-App über klar definierte PHP-Interfaces (Dependency Inversion).

## Kernidee

Eine **Definition** (Template, unveränderlich, versioniert) beschreibt einen gerichteten
Graphen aus **Steps** und **Transitions**. Eine **Instanz** ist ein laufender Durchlauf
mit eigenem Zustand (aktueller Step, Kontext, Status). Die Trennung ist bewusst: laufende
Instanzen bleiben stabil, auch wenn die Definition weiterentwickelt wird.

Step-Typen:

| Typ | Verhalten |
|-----|-----------|
| `automatic` | läuft im Hintergrund durch (führt eine Action aus, wertet dann Transitionen aus) |
| `interactive` | hält an und wartet auf ein Frontend-Event (führt den Benutzer) |
| `timer` | wartet bis zu einem Zeitpunkt; der Cron-Runner weckt die Instanz auf |

Damit deckt dasselbe Modell beide Anforderungen ab: Hintergrund-Abläufe **und**
benutzergeführte Abläufe im Frontend.

## Architektur (Ports & Adapters)

Die Engine kennt die Host-App nicht. Sie definiert **Ports** (Interfaces), die die
Host-App als **Adapter** implementiert:

- `DataProviderInterface` – Zugriff auf die Datenstruktur der App (für Bedingungen, Trigger)
- `MailerInterface` – E-Mail-Versand
- `ActionInterface` – eigene auszuführende Aktionen (in der `ActionRegistry` registriert)
- `ExpressionEvaluatorInterface` – Auswertung der Bedingungen (Default: Symfony ExpressionLanguage)
- `WorkflowRepositoryInterface` – Persistenz (Default: `PdoWorkflowRepository`, MariaDB)
- `TriggerInterface` – datengetriebene Start-Trigger, vom Cron-Runner gepollt

```
                ┌─────────────────────────────────────────┐
   API / Cron   │              WorkflowEngine               │
   ────────────▶│  start() · advance() · handleEvent()      │
                └───────┬───────────────┬──────────────┬────┘
                        │               │              │
                  Repository       ActionRegistry   Evaluator
                   (MariaDB)        (send_email,      (Symfony)
                                     eigene …)
                        ▲               ▲
                        └─ Adapter der Host-App (DataProvider, Mailer) ┘
```

## Auslösende Ereignisse (Trigger)

- **API-Trigger:** `POST /workflows/{def}/instances` bzw. `POST /instances/{id}/events`
- **Zeit/Datum:** `timer`-Steps setzen `wake_at`; der Cron-Runner weckt fällige Instanzen
- **Daten im Backend:** `TriggerInterface`-Implementierungen pollen über den `DataProvider`
  und starten Instanzen (z. B. „Rechnung > 14 Tage überfällig")

## Setup

```bash
cd backend
composer install
mysql my_db < schema.sql
# Definition einspielen:
# INSERT INTO wf_definition (id, version, name, definition)
# VALUES ('onboarding', 1, 'Onboarding', '<inhalt von examples/onboarding.json>');
```

Cron (jede Minute):

```
* * * * * php /pfad/backend/bin/run-workflows.php >> /var/log/wf.log 2>&1
```

## Bedingungen (`when`)

Transitionen nutzen Ausdrücke der Symfony ExpressionLanguage. Der Scope enthält
`context` (die Instanz-Variablen) und `now` (Unix-Timestamp):

```
context['status'] == 'approved'
context['amount'] > 1000 and context['vip']
context['dueDate'] < now
context['lastSeen'] < now - days(30)
```

**Sandbox:** Der `SymfonyExpressionEvaluator` läuft ohne eingebaute Funktionen –
auch das standardmäßige `constant()` ist deaktiviert, beliebige PHP-Funktionen sind
nicht erreichbar. Freigegeben sind nur die Zeitfunktionen `days(n)`, `hours(n)`,
`minutes(n)` (liefern Sekunden) sowie Funktionen, die die Host-App ausdrücklich
übergibt (`new SymfonyExpressionEvaluator([$ownFunction])`). Fehlende Kontext-Keys
werten zu `null` aus (kein Fehler); ungültige Ausdrücke werfen eine
`ExpressionException` statt eines Fatals.

## Frontend (Angular)

Drei Dateien in `frontend/`:

- `workflow.models.ts` – Typen passend zu den API-Antworten
- `workflow.service.ts` – `start()`, `currentStep()`, `sendEvent()`, `pollUntilStable()`
- `workflow-runner.component.ts` – rendert interaktive Steps dynamisch aus den
  `ui.fields` der Definition und sendet Events; pollt automatisch, solange der
  Workflow im Hintergrund läuft

```html
<wf-runner [instanceId]="id" />
```

Der interaktive Step liefert seine UI-Beschreibung (`ui.fields`, `events`) selbst mit –
das Frontend muss die einzelnen Workflows nicht kennen, sondern rendert generisch.

## Als Library einbinden

`examples/bootstrap.php` zeigt das vollständige Wiring: Host-App implementiert
`MailerInterface` und `DataProviderInterface`, registriert eigene Actions und setzt
`WorkflowEngine` + `WorkflowRunner` zusammen. In einer echten App gehört das in den
DI-Container.

## Nebenläufigkeit (mehrere Cron-Worker)

Der `WorkflowRunner` holt fällige Timer-Instanzen über
`WorkflowRepositoryInterface::claimDueInstances()` ab. Die PDO-Implementierung sperrt
die Zeilen mit `SELECT … FOR UPDATE SKIP LOCKED` in einer Transaktion und markiert sie
atomar als `running`. Dadurch verarbeiten parallele Cron-Läufe dieselbe Instanz nicht
doppelt (benötigt InnoDB; `SKIP LOCKED` ab MariaDB 10.6).

## Nächste sinnvolle Schritte

- Eigene Actions (`ActionInterface`) für deine Domäne registrieren
- `DataProvider.find()` mit einem sauberen Query-Builder ausimplementieren
- Retry/Backoff bei fehlgeschlagenen Actions statt direktem `failed`
- Editor im Frontend zum Erstellen/Versionieren von Definitionen
