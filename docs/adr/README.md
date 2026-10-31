# Architecture Decision Records

Architekturentscheide als kurze ADRs, eine Datei pro Entscheid, fortlaufend nummeriert.
ADR 1–7 stammen aus 2.x und gelten weiter, soweit ein späterer ADR sie nicht ablöst.

| Nr. | Titel |
| --- | --- |
| 1 | [Ports und Adapter](0001-ports-and-adapters.md) |
| 2 | [Cron und DB-Polling statt Message-Queue](0002-cron-polling-no-queue.md) |
| 3 | [Versionierung, Durchläufe bleiben auf ihrer Version](0003-definition-versioning.md) |
| 4 | [Nebenläufigkeit über `FOR UPDATE SKIP LOCKED`](0004-concurrency-skip-locked.md) |
| 5 | [Wiederholung und Idempotenz](0005-retry-and-idempotency.md) |
| 6 | [Grenze für Eingaben von aussen](0006-event-payload-boundary.md) |
| 7 | [Eigene Engine statt einer fremden](0007-eigene-engine-statt-fremder.md) |
| 8 | [Neubau v3 auf eigenem Branch](0008-neubau-v3.md) |
| 9 | [Datenmodelle statt Lese- und Schreib-Ports](0009-datenmodelle.md) |
