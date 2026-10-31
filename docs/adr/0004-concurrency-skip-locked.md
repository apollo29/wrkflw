# 4. Nebenläufigkeit über FOR UPDATE SKIP LOCKED

Status: akzeptiert

## Kontext
Mehrere parallele Cron-Worker dürfen dieselbe fällige Instanz nicht doppelt
verarbeiten – ohne Message-Queue (ADR 2).

## Entscheidung
`PdoWorkflowRepository::claimDueInstances()` selektiert fällige Timer-Instanzen in einer
Transaktion mit `SELECT … FOR UPDATE SKIP LOCKED` und markiert sie atomar als `running`.
Bereits von anderen Workern gesperrte Zeilen werden übersprungen.

## Konsequenzen
- Sichere horizontale Skalierung des Runners ohne zusätzliche Infrastruktur.
- Setzt InnoDB und MariaDB ≥ 10.6 (`SKIP LOCKED`) voraus.
- Schlägt die Verarbeitung nach dem Claim fehl, bleibt die Instanz `running`. Für
  Action-Fehler greift Retry/Backoff (ADR 5); für Worker-Abstürze zwischen Claim und
  Verarbeitung holt der Runner `running`-Instanzen nach einem **Lease-Timeout**
  (`claimDueInstances(..., staleAfterSeconds)`, `updated_at`) erneut ab.
