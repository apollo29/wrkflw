# 5. Retry/Backoff und Idempotenz beim Event-Handling

Status: akzeptiert

## Kontext
Actions können transient fehlschlagen (z. B. externer Dienst nicht erreichbar). Frontends
können Events doppelt senden (Doppelklick, Retry).

## Entscheidung
- **Retry/Backoff:** Eine fehlgeschlagene Action wird bis `maxAttempts` mit exponentiellem
  Backoff als `waiting_timer` neu eingeplant; `attempts` zählt die Versuche und wird bei
  erfolgreichem Schrittwechsel zurückgesetzt. Erst nach Erschöpfung: `failed`.
- **Idempotenz:** `handleEvent()` nimmt einen optionalen Idempotenz-Key (API-Header
  `Idempotency-Key`). Bereits angewendete Keys (im Kontext unter `__appliedEventIds`)
  führen zu einem No-op mit History-Eintrag `event_duplicate`.

## Konsequenzen
- Robust gegenüber transienten Fehlern und doppelten Submits.
- `attempts` wird als Spalte auf `wf_instance` geführt.
- Die Idempotenz-Keys wachsen im Kontext; bei sehr vielen Events ggf. begrenzen.
