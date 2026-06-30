# 2. Hintergrundausführung via Cron + DB-Polling (keine Message-Queue)

Status: akzeptiert

## Kontext
Timer-Schritte und datengetriebene Trigger brauchen Hintergrundausführung. Eine
Message-Queue wäre zusätzliche Infrastruktur und Betriebsaufwand.

## Entscheidung
Ein Cron ruft `bin/run-workflows.php` (→ `WorkflowRunner::tick()`) periodisch auf.
`tick()` weckt fällige Timer-Instanzen und pollt registrierte Trigger. Keine Queue.

## Konsequenzen
- Minimale Infrastruktur (nur DB + Cron).
- Latenz ist an das Cron-Intervall gebunden (typisch 1 Minute).
- Nebenläufigkeit mehrerer Worker muss explizit gelöst werden (siehe ADR 4).
