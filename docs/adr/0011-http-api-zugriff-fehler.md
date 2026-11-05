# 11. HTTP-API v3: Zugriff und Fehler

Status: akzeptiert (2026-11-01, Neubau 3.0 Phase 5)

## Kontext

Die API aus 2.x kannte nur einen festen API-Schlüssel und wusste nicht, wer anfragt. Für
die Datenquellen (3.11) braucht es aber zweierlei: ein Admin-Recht (F-133) und die
handelnde Person im Protokoll (F-145). Ausserdem antwortete 2.x fast überall mit
`{error: {code, message}}`, beim Löschen einer Version aber flach, und jeder Controller
übersetzte Ausnahmen auf eigene Art.

## Entscheid

**Zugriff über einen Port.** Die Host-App liefert ein `AccessResolverInterface`. Es
ermittelt aus der Anfrage ein `Access(Actor, admin)` oder `null`. Die `AccessMiddleware`
antwortet bei `null` mit 401 und legt den Zugriff sonst als Request-Attribut ab. Die
Gruppe `/data-sources` verlangt zusätzlich das Admin-Recht (403). Mitgeliefert werden:
- `ApiKeyAccessResolver`: fester Schlüssel aus `WF_API_KEY`, Actor «api» mit Admin-Recht
  (wie 2.x);
- `OpenAccessResolver`: ohne Anmeldung, nur für Entwicklung und Demo.

`ApiFactory::create()` verlangt einen Resolver; eine API ohne Zugriffsregel entsteht nur
ausdrücklich.

**Ein Fehlerformat.** Überall `{error: {code, message, details?}}`. Ein `ErrorMapper`
übersetzt an einer Stelle:

| Ausnahme | Status | `code` |
| --- | --- | --- |
| `NotFoundException`, unbekannte Route | 404 | `not_found` |
| falsche Methode | 405 | `method_not_allowed` |
| `MissingInputException` | 422 | `missing_input`, `details.missing` |
| `InvalidDefinitionException` | 422 | `invalid_definition`, `details.errors` |
| `RequestValidationException`, `DataSourceValidationException` | 422 | `validation_error`, `details.fields` |
| `InvalidStateException` | 409 | `invalid_state` |
| `ConflictException` | 409 | `not_deletable`, `in_use`, `exists` |
| alles andere | 500 | `internal_error`, ohne Einzelheiten |

**Abgleich mit der Dokumentation.** Die Routen stehen als Tabellen in der `ApiFactory`;
ein Test prüft, dass `docs/openapi.yaml` genau diese Routen beschreibt.

**Speichern von Definitionen.** «Aktiv» verlangt eine fehlerfreie Definition. Entwurf und
Inaktiv dürfen Prüffehler enthalten; die Antwort nennt sie (`validation`). So lässt sich
ein halbfertiger Ablauf sichern, starten lässt er sich nicht.

## Folgen

- Die Coach-Admin muss ihren Client an das Fehlerformat anpassen (nur beim Löschen einer
  Version) und `/data-catalog` durch `/data-models` ersetzen (Migrationsleitfaden).
- Wer echte Personen im Protokoll will, schreibt einen Resolver auf die eigene Anmeldung.
- Unbekannte Routen antworten auch ohne Anmeldung mit 404; die Routen sind ohnehin
  dokumentiert.

## Nachtrag 3.1 (2026-11-05): Einhängen in eine bestehende Anwendung

Die Coach-Admin hat eine eigene Slim-Anwendung mit Anmeldung (MSAL) und Rollen je Route
(Admin, Junior, Onboarding). Eine zweite Anwendung neben ihr wäre doppelt verdrahtet.

**Entscheid:** `ApiFactory::mount($gruppe, '/wf', $services, $resolver, $guard)` hängt
dieselben Routentabellen als Gruppe in die Anwendung der Host-App.
- Zugriff, Body-Parsing und Fehlerformat gelten nur in dieser Gruppe
  (`ErrorBoundaryMiddleware`); Fehler der Host-App behandelt weiter die Host-App.
- Der optionale Guard läuft nach dem Zugriff und bekommt den Routen-Schlüssel aus
  `routeList()`, z. B. `POST /instances/{id}/events`, und den `Access`. `false` gibt 403
  `forbidden` im Fehlerformat. Die Datenquellen bleiben zusätzlich Admin-pflichtig.
- `create()` baut weiter eine eigene Anwendung mit denselben Tabellen.

**Folge:** Rollen der Host-App brauchen keinen eigenen Code in der Engine; der Guard bildet
sie auf die Routen ab.
