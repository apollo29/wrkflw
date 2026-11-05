# Demo

Die Demo-App (`frontend/projects/demo`) zeigt Admin-Oberfläche, Runner und Bausteine.

## Mit Ersatz-Backend (ohne PHP)

```bash
cd frontend && npm ci && npx ng serve demo
```

Ein Interceptor (`fake-api.ts`) beantwortet alle Aufrufe im Browser und speichert im
`localStorage`. So laufen auch die E2E-Tests in `frontend/e2e/`.

## Mit echtem Backend

Voraussetzungen: PHP 8.4 mit `pdo_mysql` und `sodium`, Composer, Node 22, MariaDB.

```bash
# 1. Datenbank (Port 3307 aus docker-compose; lokal installierte MariaDB geht ebenso)
docker compose -f docker/docker-compose.yml up -d
export WF_DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=workflow_demo;charset=utf8mb4' WF_DB_USER=root WF_DB_PASS=root

# 2. Schema und Beispieldaten 9.13 (ersetzt alle Daten dieser Datenbank)
cd backend && composer install && php demo/seed.php

# 3. Engine
WF_API_BOOTSTRAP=$PWD/demo/api.php WF_API_OPEN=1 php -S 127.0.0.1:8080 -t api

# 4. Demo (zweites Terminal)
cd frontend && npx ng serve demo -c echt
```

Die Datenbank muss «demo» im Namen tragen, sonst bricht der Seed ab
(`WF_DEMO_SEED_FORCE=1` setzt ihn trotzdem). Die Konfiguration `echt` lässt das
Ersatz-Backend weg und leitet `/api` an `127.0.0.1:8080` weiter (`proxy.echt.json`).

**Was die Demo-Host-App (`backend/demo/services.php`) verdrahtet:**
- Ablage, Vorlagen und Datenquellen in MariaDB
- Datenmodell `antrag` auf der Tabelle `demo_antraege`
- Aktionen `send_email`, `check_data`, `write_data`, `start_workflow`
- Datei-Prüfung `pdf_max_5mb`
- Mails landen als JSON-Zeilen in `WF_DEMO_MAIL_LOG` (Standard
  `/tmp/wrkflw-demo-mails.jsonl`).

Wartende Timer weckt `bin/run-workflows.php` (siehe `docs/crontab.example`); die Demo
braucht das für die Abnahme nicht.

Die Abnahme gegen das echte Backend startet `php -S` und die Demo selbst:

```bash
cd frontend && npx playwright test -c playwright.echt.config.ts
```
