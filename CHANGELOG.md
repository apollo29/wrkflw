# Changelog

Alle nennenswerten Änderungen an diesem Projekt. Format nach
[Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionen nach
[SemVer](https://semver.org/lang/de/). Jede Änderung an einem Port oder an der Form der
HTTP-API ist eine neue Hauptversion (Q-10).

Die Einträge bis 2.3.1 stehen im CHANGELOG auf `main`.

## [Unreleased]

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
