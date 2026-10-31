# 3. Definition-Versionierung mit Pinning laufender Instanzen

Status: akzeptiert; Speicherregel in 3.0 präzisiert (siehe Nachtrag)

## Kontext
Definitionen entwickeln sich weiter. Laufende Instanzen dürfen durch eine neue
Version nicht in einen inkonsistenten Zustand geraten.

## Entscheidung
`wf_definition` ist nach `(id, version)` versioniert und unveränderlich. Eine Instanz
speichert `definition_ver` und wird über `findDefinition(id, version)` stets mit genau
dieser Version ausgeführt. `start()` ohne Version nutzt die neueste **aktive** Version.

## Konsequenzen
- Laufende Instanzen bleiben stabil; neue Versionen betreffen nur neue Instanzen.
- Alte Versionen müssen erhalten bleiben, solange Instanzen darauf laufen.
- Migration laufender Instanzen auf eine neue Version ist bewusst nicht automatisch.

## Nachtrag 3.0 (2026-10-31): Entwurf und Inaktiv

2.x überschrieb beim Speichern als «Entwurf» oder «Inaktiv» immer die aktuelle Fassung
(F-11). Verwies schon ein Durchlauf darauf, bekam dieser eine geänderte Definition
untergeschoben, was diesem ADR widerspricht. Ab 3.0 gilt:

- «Aktiv» erzeugt immer eine neue Version.
- «Entwurf»/«Inaktiv» überschreibt die aktuelle Fassung nur, solange kein Durchlauf auf
  sie verweist. Sonst entsteht eine neue Version im gewählten Status, die alte bleibt
  unverändert.
- Startbar ist ein Ablauf nur, wenn seine neueste Version «Aktiv» ist (wie 2.x).
- Jede Version trägt `updated_at`; ein überschriebener Entwurf zeigt so, wann er zuletzt
  geändert wurde (9.14).
