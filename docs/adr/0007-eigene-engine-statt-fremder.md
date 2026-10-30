# 7. Eigene Engine statt einer fremden

Status: akzeptiert (Marktbetrachtung 2026-10-30)

## Kontext

Nach drei Jahren eigener Engine die naheliegende Frage: gibt es inzwischen etwas
Fertiges, das dasselbe tut? Diese Betrachtung beantwortet sie — und hält fest,
woran eine Alternative gemessen werden müsste, damit die Frage beim nächsten Mal
nicht wieder bei null anfängt.

### Woran sich eine Alternative messen muss

Die Engine ist ~4.900 Zeilen PHP in 45 Dateien, 16 Ports, eingebettet als Library —
kein zweiter Dienst, dieselbe Slim-App, dieselbe MariaDB. Entscheidend sind aber
nicht die Zeilen, sondern vier Eigenschaften, die zusammen selten vorkommen:

1. **Eingebettet, nicht daneben.** Keine zusätzliche Laufzeit, kein Broker, kein
   JVM. Betrieb ist Cron + Datenbank (ADR 2).
2. **Interaktive Schritte für Leute ohne Konto.** Über Token-Links gehen Externe
   eine Formularstrecke durch. Das ist kein „Human Task" im BPMN-Sinn (angemeldeter
   Sachbearbeiter in einer Tasklist), sondern eine öffentliche, geführte Seite.
3. **Visueller Builder IN der Host-Anwendung.** Der Angular-Client ist eine
   Komponente der App, mit deren Rollen und Daten — kein zweites Werkzeug mit
   eigener Benutzerverwaltung.
4. **Ports auf die Daten des Hosts.** `DataProviderInterface`,
   `DataWriterInterface` und die Kataloge lesen und schreiben Host-Tabellen, ohne
   dass die Engine sie kennt (ADR 1).

Orchestrierung bekommt man überall. Punkt 2 und 3 zusammen bekommt man fast
nirgends.

## Betrachtete Alternativen

| Kandidat | Stand (10/2026) | Befund |
|---|---|---|
| **symfony/workflow** | v8.1.8, PHP ≥ 8.4.1, 51,7 Mio. Installationen, aktiv | Zustandsautomat/Petri-Netz. Keine Instanz-Persistenz, keine Timer, keine Aktionen, keine Oberfläche, keine interaktiven Schritte. Ersetzte den **kleinsten** Teil dieser Engine. |
| **phpmentors/workflower** | letztes stabiles Release **12/2019**, PHP ≥ 5.3.9 | BPMN 2.0 mit User-/Manual-Tasks — aber seit Jahren ohne Release und weit vor PHP 8.4. |
| **bingo-soft/jabe** | aktiv | Camunda/Flowable-inspiriert, laut Projekt noch **ohne REST-API**. |
| **saccharine/bpmn-engine** | **v0.3.2-alpha** | Konzeptionell am nächsten: bpmn-js-Designer, durable workflow, Human-in-the-Loop. Aber ausdrücklich nicht für Produktion und Laravel-spezifisch. |
| **Temporal** (`temporal/sdk`) | v2.20, PHP ≥ 8.2, 4,16 Mio. Installationen, aktiv | Durable Execution, erstklassige Wiederaufnahme. Braucht **Temporal-Server + RoadRunner** als Worker-Laufzeit. Kein Builder, keine Formularstrecken — beides selbst zu bauen. |
| **Camunda 8 / Flowable** | aktiv | Echte Human-Tasks, Modeler, Formulare. JVM-Betrieb; Camundas offizielle Job-Worker-SDKs nennen **kein PHP**. |
| **n8n / Activepieces / Windmill** | aktiv | Anderer Zuschnitt: eigener Dienst, eigene Oberfläche, eigene Benutzer. Lizenzen beachten — n8n *Sustainable Use License* (Einbettung in ein kommerzielles Produkt braucht Vereinbarung), Activepieces-Kern MIT mit Embed-SDK, Windmill AGPLv3 plus nicht-offene Teile im Community-Image. |

## Entscheidung

**Die eigene Engine bleibt.** Nicht aus Stolz, sondern weil die Rechnung ungünstig
ausfällt:

- Im PHP-Ökosystem gibt es **keinen reifen Ersatz**. Das Einzige, was den Zuschnitt
  trifft, steht bei v0.3.2-alpha.
- Alles, was mehr kann (Temporal, Camunda), bringt **einen zweiten Dienst** mit. Das
  ist der teuerste Posten, und zwar dauerhaft.
- Die Teile, die den Nutzen ausmachen — Token-Strecken an den Rollen und Tabellen
  des Hosts, der Builder in der eigenen Oberfläche — müssten auf **jeder**
  Alternative neu gebaut werden. Man tauschte den gelösten Teil und behielte den
  ungelösten.

## Was diese Betrachtung berichtigt hat

Zwei vermeintliche Schwächen hielten der Prüfung am Code **nicht** stand:

- *„Cron-Polling skaliert nicht."* Horizontale Skalierung ist über
  `FOR UPDATE SKIP LOCKED` gelöst (ADR 4). Der reale Preis ist **Latenz**, gebunden
  ans Cron-Intervall — und der steht in ADR 2 als bewusste Entscheidung.
- *„Keine Erholung nach Fehlern."* Es gibt Retry mit exponentiellem Backoff bis
  `maxAttempts`, Idempotenz-Keys und einen Lease-Timeout für Worker-Abstürze
  (ADR 4 + 5). Auch die in ADR 5 vermerkte Sorge ums unbegrenzte Wachstum der Keys
  ist erledigt (`MAX_APPLIED_EVENTS`).

Wer die Frage „sollten wir wechseln?" erneut stellt, möge zuerst hier nachsehen.

## Konsequenzen

- Die Lücken, die wirklich bestehen, sind als Issues erfasst statt als Gefühl:
  endgültige `failed`-Instanzen, kein Weg für laufende Instanzen auf eine neue
  Definition-Version, und kein Austauschformat.
- Der dritte Punkt ist der strategische: **solange Definitionen das Werkzeug nicht
  verlassen können, ist diese Entscheidung keine, sondern ein Zustand.** Ein
  Export-Pfad macht sie umkehrbar.
- Die Betrachtung hat ein Datum. Insbesondere `saccharine/bpmn-engine` lohnt eine
  erneute Prüfung, sobald es Produktionsreife erreicht.
