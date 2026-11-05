# Workflow Engine v3

Eingebettete Workflow-Engine als PHP-Library mit Slim-REST-API, MariaDB-Ablage und
Angular-Client. Abläufe werden als Daten beschrieben und ohne Programmierung in einem
visuellen Editor gepflegt; die Engine führt sie aus, auch über Wochen, und hält fest,
was passiert ist. Hintergrundausführung über Cron + DB-Polling.

> **Status:** 3.1.0 (Tags `vX.Y.Z` und `client-vX.Y.Z` auf Branch `v3`). Die Version 2.x
> bleibt auf `main`, bis die Coach-Admin umgestellt hat ([Migration](docs/migration-v3.md)).

## Pakete

| Paket | Inhalt | Release |
| --- | --- | --- |
| `apollo29/workflow-engine` (Composer) | Engine, REST-API, Ablage, Datenmodelle | Tag `vX.Y.Z` |
| `@apollo29/workflow-client` (npm, GitHub Packages) | Übersicht, Editor, Runner, Vorlagen, Datenquellen | Tag `client-vX.Y.Z` |

## Installieren

```bash
# Engine (Composer, aus dem Git-Repository)
composer config repositories.wrkflw vcs https://github.com/apollo29/wrkflw
composer require apollo29/workflow-engine:^3.0
```

```bash
# Client (GitHub Packages; .npmrc mit @apollo29:registry=https://npm.pkg.github.com)
npm install @apollo29/workflow-client@^3.1.0
```

Einbinden des Clients: [`frontend/projects/workflow-client/README.md`](frontend/projects/workflow-client/README.md).
Verdrahten der Engine in einer Host-App: Vorlage `backend/demo/services.php`,
Schritte in [`docs/migration-v3.md`](docs/migration-v3.md).

## Demo

```bash
cd frontend && npm ci && npx ng serve demo          # mit Ersatz-Backend im Browser
```

Mit Engine und MariaDB (Beispieldaten 9.13): [`docs/demo.md`](docs/demo.md).

## Entwickeln

```bash
# Backend
cd backend && composer install
composer test && composer stan && composer cs

# MariaDB für Integrationstests (Port 3307)
docker compose -f docker/docker-compose.yml up -d
WF_DB_DSN='mysql:host=127.0.0.1;port=3307;dbname=workflow_test;charset=utf8mb4' WF_DB_PASS=root composer test

# Frontend
cd frontend && npm ci
npm test                      # Vitest
npx ng build workflow-client
npx ng build demo
npx playwright test           # E2E gegen die Demo (Ersatz-Backend)
npx playwright test -c playwright.echt.config.ts   # E2E gegen Engine + MariaDB
```

Voraussetzungen: PHP 8.4 (mit `pdo_mysql`, `sodium`), Composer 2, Node 22.

## Dokumentation

- [`docs/abnahme.md`](docs/abnahme.md) — Abnahme 9.14–9.16 mit Belegen
- [`docs/migration-v3.md`](docs/migration-v3.md) — Umstieg von 2.x
- [`docs/demo.md`](docs/demo.md) — Demo mit Ersatz- oder echtem Backend
- [`docs/openapi.yaml`](docs/openapi.yaml) — HTTP-API
- [`PLAN.md`](PLAN.md) — Phasen des Neubaus
- [`CLAUDE.md`](CLAUDE.md) — Konventionen
- [`docs/adr/`](docs/adr/) — Architekturentscheide
- [`CHANGELOG.md`](CHANGELOG.md)

## Lizenz

MIT
