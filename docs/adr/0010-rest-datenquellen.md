# 10. REST-Datenquellen und Geheimnisse

Status: akzeptiert (2026-10-31, Neubau 3.0 Phase 4)

## Kontext

Anforderung 3.11 «REST ohne Code» (F-129 bis F-145) verlangt, dass ein Admin
REST-Verbindungen, REST-Datenmodelle und Trigger ohne Code einrichtet. Damit erreicht die
Engine erstmals Adressen, die nicht die Host-App festlegt, und speichert Zugangsdaten. Beides
braucht feste Regeln: Zugangsdaten dürfen nie sichtbar werden (F-135), und eine Verbindung
darf nicht ins interne Netz zeigen (F-136, SSRF).

## Entscheid

**Datenmodell wie jedes andere.** Ein REST-Modell (`RestModelSpec`) wird zu einem
`DataModel` mit Persistenz `rest`. Abläufe sehen keinen Unterschied zu Tabellen der Host-App.
`RestDataModelSource` liefert die Modelle an die `DataModelRegistry`, die Namenskonflikte
mit Host-Modellen meldet (F-138). Schreibbar ist ein Feld nur, wenn das Modell «Ändern» oder
«Anlegen» hat und die Host-App Schreiben über REST erlaubt (`RestOptions::allowWrites`,
F-137).

**Geheimnisse.** `SodiumSecretStore` verschlüsselt mit libsodium `secretbox` und zufälliger
Nonce, Format `v1:<key-id>:<base64(nonce|cipher)>`. Der Schlüssel kommt aus
`WF_SECRET_KEY` (32 Byte, base64), nie aus der Datenbank. Alte Schlüssel in
`WF_SECRET_KEYS_OLD` dienen nur noch zum Entschlüsseln; `bin/rotate-secret-key.php`
verschlüsselt alles mit dem aktuellen Schlüssel neu. Entschlüsselte Werte sind `Secret`:
`__toString`, `jsonSerialize` und `__debugInfo` liefern `***`, Serialisieren wirft. Die
Verwaltung kann Zugangsdaten nur ersetzen, nie anzeigen; API-Antworten melden nur
`secretStored`.

**SSRF-Schutz.** `UrlGuard` lässt nur `https` ohne Zugangsdaten in der URL zu, löst den Host
auf und sperrt, wenn eine der Adressen privat, Loopback, Link-Local, CGNAT, reserviert,
Multicast, IPv6-ULA, IPv4-mapped oder NAT64 ist. Optional gilt eine Allowlist der Host-App
(`*.example.org`). `SafeHttpClient` heftet die geprüfte IP an die Anfrage (kein
DNS-Rebinding), folgt keiner Weiterleitung selbst, prüft höchstens drei Weiterleitungen neu
und bricht bei Zeitlimit oder Grössenlimit ab (F-144).

**Fehlerarten.** 404 heisst «nicht gefunden» (F-124). 408, 425, 429 und 5xx sowie Netz-,
DNS- und Zeitfehler sind technisch und werden wiederholt (Q-01). Andere 4xx und unlesbare
Antworten sind dauerhaft (`PermanentFailureInterface`). Fehlermeldungen enthalten nur
Status und Vorgang, nie den Antworttext.

**Suche.** Gleich-Bedingungen gehen als Query-Parameter mit; alle Bedingungen werden
zusätzlich auf der Antwort geprüft. In 3.0 zählt nur die erste Seite bis `maxResults`.

**Verwaltung.** `DataSourceAdmin` ist der einzige Einstieg für die spätere API: Prüfungen
(URL, Feldtypen, Pfade, entfernte oder schreibend genutzte Felder, Trigger-Takt ≥ 5 Minuten),
Sperren beim Löschen genutzter Verbindungen und Modelle, Verbindungstest (nur Status und
Dauer), Beispielabruf ohne Speichern. Jede Änderung geht mit `Actor` und Zeitpunkt ins
Protokoll (`wf_audit`), nie mit Zugangsdaten (F-145). Die Verwendung in Definitionen liefert
der eigene Port `ModelUsageQueryInterface`.

**Trigger.** Admin-Trigger (`wf_trigger`) sind standardmässig aus. `AdminTriggerSource`
gibt dem Runner die fälligen als `ModelSearchTrigger` mit Sperrfrist in Tagen.

## Folgen

- Ohne `WF_SECRET_KEY` lassen sich keine Verbindungen mit Zugangsdaten speichern; der
  Schlüssel muss gesichert werden wie ein Datenbank-Passwort.
- Interne Dienste der Host-App sind über REST-Modelle nicht erreichbar. Wer das braucht,
  liefert ein eigenes Datenmodell mit eigener Persistenz.

## Offene Punkte

- Seitenweise Antworten, OAuth 2 und Webhooks (offener Punkt 5) folgen nach 3.0.
