# Umstieg von 2.x auf 3.0

Dieser Leitfaden wächst mit dem Neubau. Er beschreibt, was eine Host-App (zuerst die
Coach-Admin) beim Umstieg tun muss und was unverändert bleibt.

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

_Weitere Abschnitte (HTTP-API, Client) folgen mit den Phasen 5 bis 12._
