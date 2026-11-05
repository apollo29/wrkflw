# Umstieg von 2.x auf 3.0

Dieser Leitfaden beschreibt, was eine Host-App (zuerst die Coach-Admin) beim Umstieg auf
Engine `v3.0.0` und Client `@apollo29/workflow-client@3.0.0` tun muss und was unverändert
bleibt. Kapitel 7 fasst die Schritte für die Coach-Admin zusammen.

## 1. Datenbank

```bash
cd vendor/apollo29/workflow-engine/backend   # bzw. backend/ in diesem Repo
WF_DB_DSN='mysql:host=…;dbname=…;charset=utf8mb4' WF_DB_USER=… WF_DB_PASS=… php bin/migrate.php
```

Alternativ gelten `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`. Das Skript ist
mehrfach ausführbar; ein zweiter Lauf meldet «bereits auf dem neuesten Stand».

Was passiert:

- Eine 2.x-Datenbank wird an `wf_definition` ohne `wf_schema_version` erkannt. Die
  2.x-Migrationen werden nachgezogen (idempotent), danach `3.0.0-001-v3.sql`:
  - `updated_at` je Version
  - Indizes für die Instanzliste
  - Tabelle `wf_trigger_lock` (Sperrfrist für Trigger)
  - Tabelle `wf_schema_version` (Buchführung der Migrationen)
- Eine leere Datenbank erhält `schema.sql` direkt.

Was **unverändert** bleibt:

- Definitionen (JSON-Format, Versionen, Status), laufende Durchläufe samt Kontext,
  Verlauf und Vorlagen. Ein wartender 2.x-Durchlauf läuft mit der v3-Engine weiter
  (geprüft in `tests/Integration/Persistence/SchemaMigratorTest.php`).
- Die Statuswerte der Durchläufe und die Arten der Verlaufseinträge.

Verhalten, das sich ändert:

- Speichern als «Entwurf» oder «Inaktiv» erzeugt eine neue Version, wenn schon ein
  Durchlauf auf die aktuelle verweist (ADR 3, Nachtrag).
- `wf_instance.updated_at` wird von der Engine gesetzt statt von der Datenbank
  (`ON UPDATE` entfällt); die Lease für hängende Durchläufe beruht darauf.

Hinweis: Die Migrationen nutzen `ADD COLUMN IF NOT EXISTS` / `ADD INDEX IF NOT EXISTS`
und setzen deshalb MariaDB ≥ 10.6 voraus (wie die 2.x-Migrationen).

## 2. Hintergrundlauf

Der Cron ruft weiterhin jede Minute `bin/run-workflows.php` auf. Neu verdrahtet die
Host-App den Runner in einer eigenen Datei, auf die `WF_BOOTSTRAP` zeigt
(`docs/crontab.example`):

```php
// config/wrkflw-runner.php
use WorkflowEngine\Engine\WorkflowRunner;
use WorkflowEngine\Persistence\PdoTriggerLock;

$runner = new WorkflowRunner($engine, $workflowRepository, new PdoTriggerLock($pdo), logger: $logger);
$runner->addTrigger($nachweisLaeuftAb, 'kjs-mahnung', lockSeconds: 7 * 86400);

return $runner;
```

Mit `lockSeconds` startet derselbe Datensatz innerhalb der Frist höchstens einen
Durchlauf; die bisher in der Host-App gebaute Doppelstart-Sperre (offener Punkt 2)
kann entfallen.

## 3. Datenzugriff (Datenmodelle)

Die Ports `DataProviderInterface`, `DataCatalogInterface`, `DataWriterInterface` und
`DataWriteCatalogInterface` entfallen (ADR 9). Die Tabellen-Listen der Host-App werden zu
Datenmodellen:

```php
DataModel::define('js_diplom', 'J+S-Diplome')        // Name = bisheriger Tabellenname
    ->text('status', 'Status')->writable()
    ->date('gueltig_bis', 'Gültig bis')->writable()
    ->subset(['geloescht' => 0])
    ->updatable()
    ->rule(new ZeitstempelRegel())                     // bisherige Sonderfälle im Schreib-Adapter
    ->build();
```

Definitionen bleiben unverändert: `entity` ist der Modellname.

## 4. Schlüssel einrichten

REST-Verbindungen speichern Zugangsdaten verschlüsselt (ADR 10). Den Schlüssel einmal
erzeugen und in der Umgebung der Host-App setzen, nicht in der Datenbank:

```bash
php bin/rotate-secret-key.php --generate
```

Den ausgegebenen Wert als `WF_SECRET_KEY` setzen und sichern. Schlüssel wechseln: neuen
Schlüssel als `WF_SECRET_KEY`, den alten als `WF_SECRET_KEYS_OLD`, dann

```bash
php bin/rotate-secret-key.php
```

Danach `WF_SECRET_KEYS_OLD` wieder leeren. `bin/migrate.php` legt die Tabellen
`wf_rest_connection`, `wf_rest_model`, `wf_trigger` und `wf_audit` an.

## 5. HTTP-API

Die Routen aus 2.x bleiben, mit diesen Änderungen (ADR 11):

- **Zugriff:** `ApiFactory::create(ApiServices, AccessResolverInterface)`. Wer bisher
  `WF_API_KEY` nutzte, bekommt mit `ApiKeyAccessResolver` dasselbe Verhalten. Die
  Datenquellen verlangen Admin-Recht; für echte Personen im Protokoll einen eigenen
  Resolver auf die Anmeldung der Host-App schreiben.
- **Fehlerformat:** überall `{error: {code, message, details?}}`. Geändert hat sich nur
  `DELETE /workflows/{id}/versions/{v}`: statt `{error: "not_deletable", message}` kommt
  `{error: {code: "not_deletable", message}}`; eine fehlende Version gibt 404.
- **Statuscodes:** fehlende Pflichtangaben beim Start 422 (`missing_input`) statt 404;
  ungültige Definition 422 (`invalid_definition`) statt 400; bei Ereignis und «zurück» im
  falschen Zustand bleibt es 409, der Code heisst aber `invalid_state` statt `conflict`.
- **Umbenannt:** `/data-catalog` und `/data-catalog/writable` → `/data-models` und
  `/data-models?writable=1` (mit Feldtypen und Vorgängen).
- **Vorlagen:** `DELETE /templates/{id}` antwortet 204 und ist gesperrt (409 `in_use`),
  solange eine Definition die Vorlage verwendet.
- **Einstieg:** `api/index.php` lädt die Verdrahtung über `WF_API_BOOTSTRAP` (eine
  PHP-Datei, die `ApiServices` zurückgibt), wie `bin/run-workflows.php` über
  `WF_BOOTSTRAP`.

## 6. Client

Der Client 3.0 ist ein Neubau (ADR 12). Aus 1.x bleibt nur der Gedanke, nicht die API.

| 1.x | 3.0 |
| --- | --- |
| `WORKFLOW_API_BASE_URL`, `WORKFLOW_API_KEY`, `authInterceptor` | `provideWorkflowAdmin({ apiBaseUrl, apiKey \| authToken })`; der Header geht nur an `apiBaseUrl` |
| `<wf-builder>`, `<wf-template-manager>` und eigene Seiten drumherum | `workflowAdminRoutes` unter einer eigenen Route: Übersicht, Editor, Instanzen, Vorlagen, Datenquellen |
| `<wf-runner [instanceId] (completed)>` | `<wf-workflow-runner [instanceId] (finished) (stepChange)>` |
| Datei-Upload im Runner über einen festen Endpunkt | Host-Funktion `uploadFile(file, { handler, instanceId, field })` → `{ value }` oder `{ rejected }` |
| Bilder im HTML-Editor als Base64 | Host-Funktion `uploadImage(file)` → Adresse; ohne sie nur «Bild-URL» |
| Texte fest im Template | Katalog `WF_MESSAGES`; einzelne Texte über `provideWorkflowAdmin({ messages })` |
| CSS des Clients mit festen Breiten | `styles/wf.css` mit Tokens `--wfb-*`; Schriften lädt die Host-App |

Voraussetzungen: Angular ≥ 22.2 ohne zone.js, `provideHttpClient(withFetch())` und
`provideRouter(…, withComponentInputBinding())`.

```ts
// app.config.ts der Host-App
provideRouter([{ path: 'workflows-admin', children: workflowAdminRoutes }], withComponentInputBinding()),
provideWorkflowAdmin({
  apiBaseUrl: '/api/wrkflw',
  authToken: () => session.token(),
  uploadFile: (file, ctx) => uploads.forWorkflow(file, ctx),
  uploadImage: (file) => uploads.image(file),
}),
```

```json
// angular.json
"styles": ["node_modules/@apollo29/workflow-client/styles/wf.css", "src/styles.css"]
```

Das Paket liegt in GitHub Packages:

```
@apollo29:registry=https://npm.pkg.github.com
```

## 7. Coach-Admin Schritt für Schritt

1. **Engine:** Den Subtree `packages/wrkflw/` auf den Tag `v3.1.0` ziehen
   (`git subtree pull --prefix packages/wrkflw https://github.com/apollo29/wrkflw v3.1.0 --squash`).
2. **Schlüssel:** `WF_SECRET_KEY` in der Umgebung setzen (Kapitel 4). Ohne Schlüssel
   starten die Datenquellen nicht; der Rest läuft.
3. **Datenbank:** Sicherung ziehen, dann `php packages/wrkflw/backend/bin/migrate.php`
   (Kapitel 1). Laufende Durchläufe bleiben auf ihrer Version und laufen weiter.
4. **Datenmodelle:** Die bisherigen Lese- und Schreib-Ports durch Datenmodelle ersetzen
   (Kapitel 3).
   - Je App-Tabelle, die Abläufe lesen oder schreiben, ein `DataModel::define(…)` mit den
     freigegebenen Feldern.
   - Bisherige `entity`-Namen bleiben gültig, wenn das Modell gleich heisst.
5. **Verdrahtung:**
   - Die API in die bestehende Slim-Anwendung einhängen (ab 3.1, ADR 11 Nachtrag):
     `ApiFactory::mount($v1, '/wf', $services, $resolver, $guard)` in der Gruppe
     `/api/v1` mit der MSAL-Middleware. Der Resolver liefert `Access` aus der Anmeldung
     (Admin-Rolle = Admin-Recht für die Datenquellen). Der Guard bildet die heutigen Rollen
     je Route ab, z. B. `POST /instances/{id}/events` für die Onboarding-Rollen.
   - Die Datei für `bin/run-workflows.php` (`WF_BOOTSTRAP`) gibt den `WorkflowRunner`
     zurück.
   - Vorlage für beides: `backend/demo/services.php`.
6. **Cron:** unverändert jede Minute `bin/run-workflows.php` (Kapitel 2); er weckt Timer
   und prüft nun auch die Trigger der Datenquellen.
7. **Client:** Paket `@apollo29/workflow-client@3.1.0` installieren und einbinden
   (Kapitel 6).
   - Die Admin-Seite wird zu einer Route mit `workflowAdminRoutes`.
   - Den Runner auf `<wf-workflow-runner>` umstellen.
   - `uploadFile` und `uploadImage` an die Dateiablage der Coach-Admin anbinden.
8. **Prüfen:**
   - Den Beispielablauf und einen bestehenden Ablauf im Editor öffnen.
   - Eine Instanz durchspielen.
   - Eine Vorlage mit Tabelle verschicken.
   - Zum Vergleich: `docs/abnahme.md` und `docs/demo.md`.
9. **Rückweg:** Subtree auf den letzten 2.x-Stand zurücksetzen und die Sicherung aus
   Schritt 3 einspielen. Neue Daten nach dem Umstieg (Datenquellen, Versionen) gehen dabei
   verloren. Der 2.x-Code liest das v3-Schema nicht.
