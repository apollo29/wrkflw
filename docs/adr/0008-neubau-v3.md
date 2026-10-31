# 8. Neubau v3 auf eigenem Branch

Status: akzeptiert (2026-10-31)

## Kontext

Die Anforderungen (`docs/wrkflw-anforderungen.md` in coach-admin, Stand 31.10.2026)
verlangen Änderungen, die Ports, API und Oberfläche zugleich treffen:

- **Datenmodelle** ersetzen die getrennten Lese- und Schreib-Ports samt Katalogen (3.11, 5.6).
- **REST-Datenquellen** richtet ein Admin ohne Code ein, mit verschlüsselten
  Zugangsdaten und SSRF-Schutz (F-133 bis F-145).
- Der **Editor** wird neu aufgebaut (Abschnitt 9), inklusive der Fehler und Schwächen aus 9.14.
  Der heutige Builder ist eine Komponente mit 1283 Zeilen.

Jeder dieser Punkte wäre für sich eine Hauptversion. `docs/weiterentwicklung.md` empfiehlt,
Port-Änderungen zu bündeln statt sie zu wiederholen.

## Entscheid

Engine und Client werden als **3.0** neu gebaut, auf dem Branch `v3` dieses Repos, nicht im
Subtree der Coach-Admin. Bewährte Teile aus `main` werden gezielt übernommen
(`git show main:<pfad>`), nicht neu erfunden: Engine-Logik, Validator, Sperren mit
`SKIP LOCKED`, Ausdrucksauswertung, Layout-Berechnung der Ablauf-Vorschau.

Festgelegt wurden:

| Bereich | Wahl |
| --- | --- |
| Backend | PHP ≥ 8.4, Slim 4, Symfony ExpressionLanguage und HttpClient 7.4 |
| Geheimnisse | libsodium (`secretbox`), Schlüssel aus der Umgebung |
| Frontend | Angular 22, zoneless, Signals |
| Styling | eigenes CSS mit `--wfb-*`, 12er-Raster über Container Queries, kein Framework |
| HTML-Editor | TipTap 3 |
| Sprache | Oberfläche deutsch über einen austauschbaren Nachrichtenkatalog; Code englisch; Doku deutsch |
| Tests | PHPUnit 12, Vitest, Playwright |
| Bestand | Migration von 2.x wird mitgeliefert; bestehende Definitionen laufen unverändert |

Symfony bleibt bei 7.4 LTS: Es deckt die Laufzeit bis 2029 ab und hält die Abhängigkeiten
der Host-App klein.

## Folgen

- Die Coach-Admin bleibt bis zum Release auf 2.x und stellt danach mit dem Leitfaden
  `docs/migration-v3.md` um.
- Zwei Versionen sind eine Zeit lang parallel zu pflegen. Fehlerbehebungen in 2.x werden,
  wo sinnvoll, nach v3 übernommen.
- Ohne Bootstrap und Tailwind passt sich der Client über CSS-Variablen an jede Host-App an,
  dafür sind Raster und Grundbausteine selbst zu pflegen.
