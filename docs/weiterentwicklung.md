# Weiterentwicklung — Befunde und Vorschläge

Stand 2026-10-30. Grundlage: Code-Analyse des gesamten Repos, die Marktbetrachtung
aus [ADR 7](adr/0007-eigene-engine-statt-fremder.md) und eine Prüfung gegen
[atk4/data](https://github.com/atk4/data).

Die Vorschläge sind **nach Nutzen geordnet**, nicht nach Aufwand. Am Ende steht,
was bewusst **nicht** vorgeschlagen wird — ein Plan ohne Verzicht ist eine
Wunschliste.

---

## Zum Zustand des Codes

Damit die Kritik unten einzuordnen ist, zuerst der ehrliche Gesamteindruck:

| | |
|---|---|
| Backend | 4.932 Zeilen in 45 Dateien |
| Tests | 6.838 Zeilen in 35 Dateien — Verhältnis **1,39 : 1** |
| Ungetestet | **eine** Nicht-Interface-Klasse (`UploadHandlerController`) |
| Frontend | 8.094 Zeilen, davon 1.808 Spec |

Das ist ein gut gepflegtes Projekt. Die Ports sind sauber geschnitten, die
Entscheidungen sind als ADRs begründet, und die Testabdeckung trägt. Die Punkte
unten sind **Weiterentwicklung**, nicht Sanierung.

---

## 1. Der Katalog kennt keine Feldtypen

**Befund.** `DataCatalogInterface` und `DataWriteCatalogInterface` liefern beide
`list<array{entity:string,label:string,fields:list<string>}>` — Felder sind
**blosse Namen**. Die Engine weiss nicht, ob `geburtsdatum` ein Datum, `anzahl`
eine Zahl oder `status` eine Auswahl aus festen Werten ist.

**Was daran hängt.** Mehr, als es zunächst aussieht:

- Der Übergangs-Assistent kann keine **typgerechten Operatoren** anbieten. Für ein
  Datum wären „vor/nach" sinnvoll, für eine Zahl „grösser/kleiner", für eine
  Auswahl nur „ist/ist nicht" samt Werteliste. Heute gibt es für alles dasselbe.
- `write_data` kann einen Wert **nicht gegen die Spalte prüfen**, bevor die
  Definition gespeichert wird. Ein Text in einer Datumsspalte fällt erst zur
  Laufzeit auf — im Log, lange nach dem Speichern.
- Für Auswahlfelder muss der Autor die gültigen Werte **auswendig kennen**.

**Vorschlag.** Den Katalog um Typ-Angaben erweitern, rückwärtskompatibel:

```php
list<array{
  entity: string,
  label: string,
  fields: list<string>,                                   // bleibt
  fieldTypes?: array<string, array{                       // neu, optional
      type: 'string'|'int'|'float'|'bool'|'date'|'datetime'|'enum',
      options?: list<string>,                             // nur bei enum
      nullable?: bool,
  }>,
}>
```

Wer `fieldTypes` weglässt, bekommt genau das heutige Verhalten. Der
`DefinitionValidator` prüft, wo Typen da sind, und schweigt, wo sie fehlen.

**Warum zuerst.** Es ist die Voraussetzung für mehrere andere Verbesserungen und
die einzige Änderung hier, die einen Port berührt — solche Änderungen bündelt man
besser, statt sie zu wiederholen.

---

## 2. Beobachtbarkeit: man sieht nicht, was läuft

**Befund.** `WorkflowRepositoryInterface` kennt `findInstance` (eine),
`findDueInstances` (die fälligen) und `findHistory` (einer Instanz).
**Es gibt keine einzige Aggregat-Abfrage.** Nicht „wie viele Instanzen stehen auf
`failed`", nicht „welche laufen seit wann", nicht „welcher Schritt scheitert am
häufigsten".

Der beste Beleg, dass die Lücke real ist: die bekannte Host-App hat sich ihre
Instanzen-Übersicht **selbst gebaut**, mit eigenen SQL-Abfragen am Port vorbei.
Was jeder Host nachbaut, gehört in die Engine.

**Aus der Marktbetrachtung.** Der im Alltag grösste Vorsprung von Temporal und
Camunda ist nicht die Haltbarkeit — es ist, dass man **sehen** kann, was hängt und
warum. Das ist der Teil, der sich hier billig nachbauen lässt: ein paar
Aggregat-Abfragen, kein neuer Dienst, keine neue Abhängigkeit.

**Vorschlag.** Einen schmalen Port, bewusst klein gehalten:

```php
interface InstanceQueryInterface
{
    /** @return array<string,int> Status => Anzahl */
    public function countByStatus(?string $definitionId = null): array;

    /** @return list<array{id:string,definitionId:string,step:string,status:string,since:string,lastError:string}> */
    public function listInstances(InstanceFilter $filter): array;

    /** @return list<array{step:string,failures:int}> Wo es am häufigsten klemmt */
    public function failureHotspots(int $limit = 10): array;
}
```

`failureHotspots()` ist der eigentliche Gewinn: sie beantwortet die Frage, die man
nach einer Störung tatsächlich stellt — *welcher Schritt macht Ärger* —, und zwar
aus der Historie, die ohnehin geschrieben wird.

---

## 3. Der Builder ist eine Einheit zu gross

**Befund.** `workflow-builder.component.*` umfasst zusammen **2.795 Zeilen**:
1.283 TypeScript (92 Methoden, 18 Signale), 878 HTML, 634 CSS mit **98
verschiedenen Klassen**. Eine Komponente besitzt 98 Widgets.

**Das ist nicht theoretisch.** In 2.3.0 wurde die allgemeine Knopf-Regel um einen
Ausschluss ergänzt:

```css
.wfb button:not(.wfb__chip) { … }
```

`:not()` übernimmt die Spezifität seines Arguments. Aus (0,1,1) wurde (0,2,1) —
und damit schlug die Regel drei **völlig unbeteiligte** Widgets: das Löschkreuz
bekam Rahmen und Hintergrund, die Karten unter „Schritt hinzufügen" verloren ihre
Farbfläche, und in „Vorhandene laden" presste eine feste Höhe Name und ID
übereinander. Behoben in 2.3.1 mit `:where(:not(…))`.

Die Ursache ist nicht der Tippfehler, sondern die **Reichweite**: in einem
Stylesheet mit 98 Klassen hat jede allgemeine Regel 98 mögliche Opfer.

**Vorschlag.** Entlang der natürlichen Naht trennen: die Detail-Panels der sechs
Schritt-Typen (`automatic`, `interactive`, `timer`, `workflow`, `datacheck`,
`datawrite`) sind sechs unabhängige Editoren, die ausser dem Modell nichts teilen.
Jeder als eigene Komponente mit **eigenem** Stylesheet. Danach kann eine Regel im
Datencheck-Editor den Timer-Editor nicht mehr erreichen.

**Der Präzedenzfall steht schon da.** `flow-geometry.ts` wurde aus genau diesem
Grund herausgelöst — Angular-frei und damit nachrechenbar statt nur ansehbar — und
hat sich bewährt. Dieselbe Bewegung, eine Ebene höher.

---

## 4. Simulation vor dem Aktivieren

**Befund.** Der `DefinitionValidator` prüft die Definition **strukturell**:
Sackgassen, ungültige Ausdrücke, fehlende Ereignis-Übergänge. Was er nicht
beantwortet: *welchen Weg nimmt dieser Ablauf eigentlich?*

**Vorschlag.** Ein `simulate(WorkflowDefinition $def, array $kontext): list<Schritt>`
— läuft den Graphen durch, wertet Bedingungen aus, **führt aber keine Aktionen
aus**. Im Builder neben der Ablauf-Vorschau: Beispielkontext eingeben, Weg sehen.

Das fängt die Klasse von Fehlern, die strukturell gültig und fachlich falsch ist:
eine Bedingung auf ein Feld, das der Startkontext nie liefert, eine Verzweigung,
deren „Sonst"-Zweig in der Praxis immer greift. Heute merkt man das am ersten
echten Durchlauf — mit einem echten Menschen am anderen Ende.

Mit Vorschlag 1 (Feldtypen) wird es deutlich besser: der Beispielkontext lässt
sich aus den deklarierten `inputs` samt Typen **vorbefüllen**, statt ihn von Hand
zu tippen.

---

## 5. atk4/data: die Idee übernehmen, nicht die Bibliothek

Ausführlich in Issue #22. Die Kurzfassung und was daraus folgt:

**Heute nicht machbar.** Die veröffentlichte `atk4/data` 6.0.0 (04/2025) verlangt
`php >=7.4 <8.4`, diese Engine `>=8.4`. Composer weist sie ab. PHP-8.4-Fähigkeit
gibt es nur im `develop`-Zweig, seit über 18 Monaten ohne Release. Eine
eingebettete Library darf ihre Hosts nicht auf einen Entwicklungszweig zwingen.

**Die übertragbare Idee.** atk4 kennt **Conditions**: Einschränkungen am Modell,
die festlegen, welche Datensätze sichtbar sind **und welche geschrieben werden
dürfen**.

Der hiesige Katalog ist dagegen eine **statische Whitelist**: Entität plus
Spaltenliste. Er kann „Tabelle `trainer`, Spalte `js_status`" erlauben — aber
nicht „nur Trainer der laufenden Saison". Zeilen-Ebene gibt es nicht.

Für einen Schritt, der aus einer **im Editor bearbeitbaren** Definition heraus in
Host-Tabellen schreibt, ist das die interessantere Hälfte der Frage.

**Vorschlag.** Den Schreib-Katalog um eine optionale Bedingung je Entität
erweitern, die der Host-Adapter durchsetzt:

```php
array{entity:string, label:string, fields:list<string>, scope?:string}
```

Die Engine **interpretiert** `scope` nicht — sie reicht ihn an den
`DataWriterInterface` weiter, der ihn in sein eigenes SQL einbaut. Damit bleibt
ADR 1 unberührt: die Engine kennt weiterhin keine konkrete Persistenz.

**Und der Nebenbefund, der unabhängig von atk4 gilt.** Die Dokumentation von
`DataWriteCatalogInterface` sagt es selbst:

> Dieselbe Liste muss der `DataWriterInterface` benutzen. Zwei gepflegte Listen
> driften.

Genau das ist die Schwachstelle. Beide Listen von Hand gepflegt, beide eine zweite
Wahrheit neben dem Schema. Laufen sie auseinander, bietet der Builder Felder an,
die der Host beim Ausführen abweist — und das fällt erst im Log auf. Ein Katalog,
der sich aus `information_schema` speist, löste das **ohne** jede neue
Abhängigkeit. Das ist der eigentliche Ertrag dieser Prüfung.

---

## 6. Kleinkram

- `UploadHandlerController` ist die einzige Nicht-Interface-Klasse ohne Test.
- `WorkflowEngine` hat 807 Zeilen und 25 Methoden in mindestens vier
  Verantwortungsgruppen. Die Rückwärts-Navigation (`goBack`, `zurueckSchieben`,
  `canGoBack`, `backTarget`, `parentIdOf` — fünf Methoden, ~130 Zeilen) ist eine
  saubere Naht, die Unter-Ablauf-Behandlung die zweite. **Niedrige Priorität:**
  die Klasse ist gut getestet und tut, was sie soll. Grösse allein ist kein
  Mangel — anders als beim Builder, wo sie nachweislich Fehler erzeugt hat.

---

## Was bewusst NICHT vorgeschlagen wird

**Saga / automatische Kompensation.** Scheitert ein Ablauf im fünften Schritt,
macht niemand die ersten vier rückgängig. Das klingt nach einer Lücke, ist aber
auch bei Temporal manuell — und ein Kompensations-Schritttyp brächte viel Begriff
für seltenen Nutzen. Wer ihn braucht, baut ihn heute schon als eigene Aktion.

**BPMN-Import.** Der Weg **hinaus** lohnt (Issue #19, Umkehrbarkeit). Der Weg
hinein nicht: der Builder ist der bessere Einstieg, und die Abbildung von BPMN auf
`check_data`, `write_data` und Seiten-Vorlagen wäre verlustbehaftet in der
unangenehmen Richtung.

**Eine Message-Queue.** Siehe Issue #21 — nicht die Queue ist der Vorschlag,
sondern ein `SchedulerInterface`, der dem Host die Wahl lässt. Die Engine soll
keine bekommen.

**Mandantenfähigkeit, Rollen, Rechte.** Gehört in den Host. Die Engine hat keine
Benutzer und sollte keine bekommen.

---

## Reihenfolge

1. **Feldtypen im Katalog** (1) — Voraussetzung für mehreres, berührt einen Port
2. **Beobachtbarkeit** (2) — günstig, hoher Nutzen, jeder Host baut es sonst selbst
3. **Builder trennen** (3) — hat schon echte Fehler gekostet
4. **Simulation** (4) — baut auf 1 auf
5. **Schreib-Bedingungen** (5) — nach 1, unabhängig von atk4
6. **Kleinkram** (6) — wenn ohnehin jemand in der Datei ist

Die Punkte 1, 2 und 5 berühren Ports und gehören daher in **eine** Hauptversion,
statt die Host-Adapter dreimal nacheinander zu migrieren.
