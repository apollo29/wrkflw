# 3. Definition-Versionierung mit Pinning laufender Instanzen

Status: akzeptiert

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
