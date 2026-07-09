# Plan: offene Issues (#1–#4) — ✅ ERLEDIGT

> **Status: abgeschlossen (2026-07-09).** Alle vier Issues sind umgesetzt und released;
> die GitHub-Issues sind geschlossen.
>
> - **#3** send_email (from/cc/bcc + Vorlagen-UX) → **v1.12.0** / client 1.10.0
> - **#4** Seiten-Vorlagen → geschlossen (bereits durch v1.11.0 erfüllt)
> - **#1** Workflow-Status (aktiv/inaktiv/entwurf) → **v1.13.0** / client 1.11.0
> - **#2** Datencheck-Schritt → **v1.14.0** / client 1.12.0
>
> Das Folgende ist der ursprüngliche Arbeitsplan (zur Nachvollziehbarkeit belassen).

Arbeitsplan für die vier offenen GitHub-Issues. Pro Issue eine PLAN-Phase = eigene
Session (Plan-Modus → Tests zuerst → implementieren → `composer test`/`stan`/`cs` +
`ng test`/`ng build` grün → Commit + CHANGELOG). Konventionen wie in `CLAUDE.md`.

Empfohlene Reihenfolge (Quick Wins zuerst, größte/offenste zuletzt):
**#3 → #4 → #1 → #2.**

### Festgelegte Entscheidungen (mit Nutzer abgestimmt)
- **#3:** `MailerInterface` wird über ein readonly-DTO `EmailMessage` erweitert (neue
  Port-Methode `send(EmailMessage)`); Host-Adapter migrieren einmalig.
- **#1:** `inaktiv` und `entwurf` sind **funktional identisch** (keine neue Version beim
  Speichern, nicht getriggert/startbar) — Unterschied nur als Label/Semantik.
- **#2:** Neue Action `check_data` lädt den Wert in den Kontext; Vergleich über den
  **Übergangs-Assistenten**. Im Builder als eigene Karte „Datencheck" (Zucker über
  `automatic + check_data`).

---

## Issue #3 — `send_email`: From/CC/BCC + Template-UX-Bug

**Ziel:** Absender (leer = Standard-Mailbox), CC/BCC konfigurierbar; und der UX-Bug:
bei ausgewählter Vorlage dürfen die Inline-Felder (Betreff/Inhalt) nicht mehr erscheinen.

### Teil A — UX-Bug (klein, sofort umsetzbar, keine offene Frage)
Im Builder (`workflow-builder.component.html`, `automatic`-Block) werden aktuell **alle**
`actionSchema`-Felder immer gerendert. Wenn bei `send_email` eine Vorlage (`templateId`)
gewählt ist, sollen `subject` und `body` **ausgeblendet** werden (die Vorlage liefert beide).
- Umsetzung: im `@for (field of actionSchema(step))` die Felder `subject`/`body`
  überspringen, wenn `configValue(step,'templateId') !== ''`. Hilfsmethode
  `isFieldHiddenByTemplate(step, field)` in `workflow-builder.component.ts`.
- Tests: builder-spec — mit gesetztem `templateId` erscheinen subject/body nicht.

### Teil B — From/CC/BCC (Backend-Port-Entscheidung nötig)
- **Entscheidung (offen):** `MailerInterface::send()` um `from`/`cc`/`bcc` erweitern ist ein
  Breaking Change für Host-Adapter (Signatur). Zwei Optionen:
  1. **DTO** `EmailMessage` (readonly: to, from, cc[], bcc[], subject, body, vars) + neue
     Port-Methode `send(EmailMessage $m)`. Sauber, aber Host-Adapter müssen migrieren.
  2. Optionale Parameter an `send()` anhängen. Weniger invasiv im Aufruf, aber Implementierer
     müssen die Signatur ebenfalls erweitern → faktisch auch Breaking.
  → Empfehlung: **Option 1 (DTO)**, konsistent und erweiterbar.
- `SendEmailAction`: configSchema um `from`, `cc`, `bcc` (Typ `text`); leeres `from` =
  Standard-Mailbox (der Host-Mailer entscheidet den Default). „Standard-Mailbox" ist damit
  Host-Konfiguration (Konstruktor-Param des Adapters), keine Engine-Sache.
- `examples/bootstrap.php` (`AppMailer`) + Beispiel anpassen.
- Tests: Unit-Test der Action (DTO korrekt befüllt, CC/BCC als Liste, leeres from).

---

## Issue #4 — Seiten-Vorlagen: WYSIWYG + Platzhalter wie E-Mail

**Status: weitgehend bereits umgesetzt (v1.11.0).** Der `WorkflowTemplateManagerComponent`
nutzt für `email` **und** `page` denselben `<wf-html-editor>` inkl. `[placeholders]`; nur der
Betreff ist bei `page` ausgeblendet. Editor-Aussehen ist damit identisch.

**Rest / zu prüfen:**
- Verifizieren, dass beim Umschalten auf „Seite" der volle WYSIWYG-Editor (inkl. Toolbar,
  Bild-Upload, Layout-Blöcke) erscheint — ggf. war die ursprüngliche Beobachtung vor v1.11.0.
- Optional: seiten-spezifische Platzhalter statt der festen E-Mail-Liste
  (`['name','email','firma','datum']`) — z. B. aus den Feldern des referenzierenden Schritts.
- Wahrscheinlich reicht **Verifikation + Issue schließen** (ggf. mit kleinem Platzhalter-Tweak).

---

## Issue #1 — Workflow-Status: aktiv / inaktiv / entwurf

**Ziel:** Jede Definition hat einen Lebenszyklus-Status.
- **aktiv:** wie heute — Speichern legt eine **neue Version** an, wird ausgeliefert/getriggert.
- **inaktiv / entwurf:** Speichern legt **keine neue Version** an (überschreibt die Zeile);
  diese Workflows werden **nicht** gestartet/getriggert.

### Backend
- Migration `002_wf_definition_status.sql`: Spalte `status VARCHAR(16) NOT NULL DEFAULT
  'active'` (idempotent). Backfill: `status='active'` wo `active=1`, sonst `'inactive'`.
  `active` bleibt als „ausgelieferte Version"-Flag bestehen.
- `WorkflowRepositoryInterface::saveDefinition(...)` bekommt einen Modus:
  - `active`: INSERT neue Version, andere Versionen `active=0`, `status='active'`.
  - `draft`/`inactive`: **UPDATE** der jüngsten (Entwurfs-)Zeile statt INSERT; `active=0`,
    `status` entsprechend. (Regel festlegen: existiert noch keine Zeile → erste Version
    anlegen, aber `active=0`.)
- `listDefinitions()` liefert `status` mit; `findDefinition(active=1)` bleibt für das
  Ausliefern/Starten (Entwürfe/inaktive sind so automatisch nicht startbar → `engine->start`
  wirft bereits). Trigger (`WorkflowRunner`) startet ohnehin nur über `findDefinition`.
- Laufende Instanzen referenzieren eine feste Version → bleiben unberührt.

### Frontend
- Builder: Status-Auswahl (Aktiv/Inaktiv/Entwurf) in der Top-Bar; steuert das Speichern
  (`saveDefinition` mit Status). Anzeige des Status in der Definitionsliste.
- Modelle/Service: `DefinitionSummary.status`; `saveDefinition(..., status)`.

### Offene Entscheidungen
- Unterscheiden sich **inaktiv** und **entwurf** funktional, oder nur im Label? (Vorschlag:
  Engine-Verhalten identisch — beide nicht ausgeliefert/getriggert; Unterschied nur semantisch.)
- „Überschreiben statt neue Version" bei Entwurf: pro id genau **eine** Entwurfszeile?
  (Vorschlag: ja — Entwurf lebt auf einer eigenen, nicht ausgelieferten Version.)

### Tests
- Repository-Integrationstest: Speichern als Entwurf legt keine neue Version an; Aktivieren
  legt eine an und deaktiviert Vorgänger. `findDefinition` liefert nur aktive.
- Unit: DefinitionController `save` mit Status.

---

## Issue #2 — Neuer Schritt: Wert in DB/Tabelle prüfen

**Ziel:** In einem Schritt eine Tabelle wählen, einen Wert daraus lesen und vergleichen.

### Ansatz (Empfehlung)
Reuse der vorhandenen Bausteine statt neuer Engine-Primitive:
- Neue eingebaute Action **`check_data`** (automatischer Schritt), die über
  `DataProviderInterface` einen Wert lädt und in den Kontext schreibt; der **Vergleich**
  läuft über den vorhandenen **Übergangs-Assistenten** (Feld/Operator/Wert). Damit ist
  „im weiteren Schritt vergleichen" = Transition-Bedingung — konsistent mit dem Rest.
- Action-Config: `entity` (Tabelle), `idExpr` (welche id, z. B. `context['userId']`),
  `field` (Spalte), `as` (Kontext-Key fürs Ergebnis). Ergebnis wird gemerged, Transitionen
  verzweigen darauf.

### Neuer Port + Endpunkt (für die Tabellen-/Feld-Auswahl im Builder)
- `DataProviderInterface` kennt nur `get`/`find` — es fehlt ein **Katalog**. Neuer Port
  `DataCatalogInterface { entities(): list<{entity, label, fields: string[]}> }`, vom Host
  implementiert (die Whitelist steckt heute in `AppDataProvider::table()`).
- Endpunkt `GET /data-catalog` (neuer `DataCatalogController`), analog `GET /actions`.
- Builder: neuer Feldtyp `entity-ref` (Dropdown aus Katalog) + `field-ref` (Spalten der
  gewählten Entity).

### Engine/Wiring
- `CheckDataAction` bekommt `DataProviderInterface` (Host-Adapter, in `bootstrap.php`
  registriert, analog `send_email`). Kein Direktzugriff auf App-Tabellen (Architektur-Regel).

### Offene Entscheidungen
- **Dedizierter Schritt-Typ** (Builder-Karte „Datencheck", analog „Workflow") oder nur eine
  Action? (Vorschlag: Builder-Karte als Zucker über `automatic + check_data`.)
- Vergleich über **Transition-Assistent** (Vorschlag) oder **eingebauter** Operator/Wert im
  Schritt, der ein boolesches Ergebnis setzt?
- Katalog: statische Whitelist (Host) genügt, oder Felder dynamisch aus dem Schema lesen?

### Tests
- Engine-Unit mit In-Memory-DataProvider-Fake: `check_data` lädt Wert, Transition verzweigt.
- API: `GET /data-catalog`. Builder-spec: `entity-ref`/`field-ref` rendern.

---

## Versionierung
- #3, #1, #2 ändern Library **und** Backend → je ein Repo-Release (`vX`) + ggf.
  npm-Release (`client-vX`), wie gehabt. #4 ist voraussichtlich reine Verifikation.
- Interface-Änderungen (`MailerInterface`, neue Ports) im CHANGELOG als **Breaking** für
  Host-Adapter markieren.
