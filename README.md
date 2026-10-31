# Workflow Engine v3

Eingebettete Workflow-Engine als PHP-Library mit Slim-REST-API, MariaDB-Ablage und
Angular-Client. Abläufe werden als Daten beschrieben und ohne Programmierung in einem
visuellen Editor gepflegt; die Engine führt sie aus, auch über Wochen, und hält fest,
was passiert ist. Hintergrundausführung über Cron + DB-Polling.

> **Status:** Neubau in Arbeit (Branch `v3`). Die produktive Version ist 2.x auf `main`.
> Fortschritt und Reihenfolge stehen in [`PLAN.md`](PLAN.md).

## Pakete

| Paket | Inhalt | Release |
| --- | --- | --- |
| `apollo29/workflow-engine` (Composer) | Engine, REST-API, Ablage, Datenmodelle | Tag `vX.Y.Z` |
| `@apollo29/workflow-client` (npm, GitHub Packages) | Übersicht, Editor, Runner, Vorlagen, Datenquellen | Tag `client-vX.Y.Z` |

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
npx playwright test           # E2E gegen die Demo
```

Voraussetzungen: PHP 8.4 (mit `pdo_mysql`, `sodium`), Composer 2, Node 22.

## Dokumentation

- [`PLAN.md`](PLAN.md) — Phasen des Neubaus
- [`CLAUDE.md`](CLAUDE.md) — Konventionen
- [`docs/adr/`](docs/adr/) — Architekturentscheide
- [`CHANGELOG.md`](CHANGELOG.md)

## Lizenz

MIT
