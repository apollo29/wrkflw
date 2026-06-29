# Changelog

Alle nennenswerten Aenderungen an diesem Projekt werden hier dokumentiert.
Format orientiert an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/).

## [Unreleased]

### Added — Phase 1: Domaenenmodell & Definition-Parsing
- Typisierte, readonly Value-Objects: `Transition`, `Step`, `WorkflowDefinition`
  (`src/Definition`) und `WorkflowInstance` (`src/Instance`).
- Strikte `fromArray()`-Parser: validieren Struktur und Typen und werfen bei
  fehlerhafter Definition `InvalidDefinitionException`.
- `DefinitionValidator`: prueft Existenz des Start-Steps, gueltige Transition-Ziele,
  unerreichbare Steps und Zyklen ohne Ausgang (Endzustand muss erreichbar sein).
- Exceptions: `WorkflowException` (Basis) und `InvalidDefinitionException`
  (buendelt mehrere Fehlermeldungen, abrufbar via `errors()`).
- Unit-Tests fuer Happy Path (`examples/onboarding.json`) und alle Fehlerfaelle.

### Added — Phase 0: Setup & Tooling
- Monorepo-Struktur: `backend/`, `frontend/`, `docker/`, `docs/`.
- Backend-Tooling: `composer.json` (PHP >= 8.4, Slim 4, Symfony ExpressionLanguage;
  Dev: PHPUnit 11, PHPStan, php-cs-fixer) mit Scripts `test`, `stan`, `cs`, `cs:fix`.
- PHPUnit-Konfiguration mit getrennten Suites `Unit` / `Integration`.
- PHPStan auf Level `max`, php-cs-fixer auf PSR-12.
- Platzhalter-Smoke-Tests, damit `composer test` gruen laeuft.
- `docker-compose.yml` (MariaDB 11.4 + PHP-CLI) und `.env.example`.
- Angular-Workspace mit Library `workflow-client` und Demo-App.
- Doku-Geruest: `docs/openapi.yaml` (Stub), `docs/adr/`.
- Referenzdaten aus dem Scaffold uebernommen: `schema.sql`, `examples/onboarding.json`.
