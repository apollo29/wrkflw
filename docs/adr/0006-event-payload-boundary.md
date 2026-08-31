# 6. Event-Payload-Grenze: reservierte Kontext-Schlüssel und Feld-Whitelist

Status: akzeptiert

## Kontext

`handleEvent()` merged den Payload eines Events bisher ungefiltert in den Instanz-Kontext.
Aus genau diesem Kontext interpoliert `send_email` seinen Empfänger (`to`, `cc`, `bcc`) und
`check_data` seine Datensatz-ID. Ein Payload ist damit nicht bloss fachliche Eingabe, sondern
steuert das Verhalten der Engine mit.

Zwei Klassen von Schaden, beide reproduziert:

1. **Engine-interne Schlüssel** (`__`-Prefix) waren per Payload setzbar. `__appliedEventIds`
   überschreibt die Idempotenz-Liste, die `markEventApplied()` zwei Zeilen vorher geschrieben
   hat — derselbe Idempotenz-Key wirkt danach ein zweites Mal. `__awaitWorkflow` und
   `__parent` steuern die Verknüpfung von Workflows; wer beide setzen kann, setzt eine
   **fremde** Instanz über ihren interaktiven Schritt hinaus fort.
2. **Fachliche Schlüssel, die der Schritt nie vorgesehen hat.** Ein Schritt zeigt zwei Felder
   an; der Payload bringt ein drittes mit, und ein späterer `send_email`-Schritt interpoliert
   es als Empfänger.

Die Host-Anwendung coach-admin filtert seit einem eigenen Fix an ihrer öffentlichen Route.
Das schützt genau eine Route — die Engine-eigene API und jeder andere Einbettungspunkt blieben
offen.

## Entscheidung

Die Grenze liegt in `handleEvent()`, an genau einer Stelle, in zwei Ebenen.

**Ebene A — immer, unabhängig von jeder Konfiguration:** Payload-Schlüssel mit dem Prefix `__`
werden verworfen. `__` ist damit ein für die Engine reservierter Namensraum; die Definition
steht in `ContextKeys`.

**Ebene B — geschaltet über `EventPayloadPolicy` im Konstruktor:**

| | |
|---|---|
| `Allow` (Default) | nur Ebene A |
| `Report` | wie `Allow`, protokolliert aber, was `Enforce` verwerfen würde |
| `Enforce` | nur die in `ui.fields` deklarierten Feldnamen; deklariert ein Schritt keine, kommt nichts durch |

Verworfen wird **still gegenüber dem Aufrufer, sichtbar in der History**: neuer Eintrag
`event_payload_rejected` mit den Schlüsselnamen — nie mit Werten, weil ein Payload
Personendaten trägt.

## Verworfene Alternativen

- **Whitelist implizit, sobald ein Schritt `ui.fields` deklariert.** Klingt nach einem Opt-in
  ohne Schalter, ist aber schwächer als der Host: `examples/order-check.json` hat einen
  interaktiven Schritt mit `ui`, aber ohne `fields` — ein reiner Button-Schritt, der per
  Konstruktion keinen Payload braucht und so vollständig offen bliebe. Ein Sicherheitsniveau,
  das an der Sorgfalt des Definitionsautors hängt, schützt die Definitionen nicht, die es am
  nötigsten hätten.
- **Ein Flag in der Definition.** Der Schalter läge in genau dem Dokument, dessen Inhalt man
  misstraut — Definitionen werden über die API geschrieben.
- **Ein optionaler Parameter an `handleEvent()`.** Verschiebt die Entscheidung an die
  Aufrufstelle, die heute schon zu filtern vergisst. Eine Tiefenverteidigung, die man durch
  Weglassen eines Arguments aushebelt, ist keine.
- **Eine Exception statt Verwerfen.** Ein zusätzlicher Schlüssel aus einem älteren Client
  würde den ganzen Schritt blockieren. Aus einer Härtung würde ein neuer Fehlerpfad.
- **`mergeContext()` härten.** Dieselbe Methode übernimmt auch Action-Ergebnisse, und
  `SubWorkflowAction` liefert legitim `__awaitWorkflow` zurück. Ein Filter dort zerstörte
  verknüpfte Workflows. Die Grenze gehört an die Event-Kante, nicht an den Kontext.

## Konsequenzen

- Die Engine ist ohne Konfiguration gegen die drei Injektionen aus Ebene A geschützt. Der
  Default `Allow` ändert für bestehende Anwendungen sonst nichts.
- `ui.fields` bekommt eine sicherheitsrelevante Bedeutung, ohne getypt zu sein
  (`DefinitionValidator` fasst `ui` nicht an). Alles Unbrauchbare zählt deshalb als «keine
  Felder deklariert» — unter `Enforce` fail closed.
- Feldnamen mit `__`-Prefix werden aus der Whitelist entfernt: eine Definition darf den
  reservierten Namensraum nicht wieder öffnen. **Ebene A gewinnt immer.**
- Der Weg für eine bestehende Anwendung ist `Allow` → `Report` → `Enforce`.
- Der Default wechselt in 2.0 auf `Enforce`.
- Ergänzt ADR 0005: dessen Idempotenz-Zusicherung war ohne Ebene A nicht haltbar.
