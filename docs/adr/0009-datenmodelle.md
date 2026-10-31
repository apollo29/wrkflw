# 9. Datenmodelle statt Lese- und Schreib-Ports

Status: akzeptiert (2026-10-31, Neubau 3.0 Phase 2)

## Kontext

In 2.x beschrieb jede Host-App auf eigene Art, welche Daten ein Ablauf lesen und schreiben
darf: über vier Ports (`DataProviderInterface`, `DataCatalogInterface`,
`DataWriterInterface`, `DataWriteCatalogInterface`), Listen von Tabellen und Spalten im
Adapter und Sonderfälle wie Zeitstempel im Schreib-Adapter. Felder waren blosse Namen ohne
Typ (`docs/weiterentwicklung.md`, Punkt 1). Für REST-APIs (F-133 ff.) hätte es eine
weitere, eigene Lösung gebraucht.

Die Anforderungen 3.11 und 5.6 verlangen eine Stelle, die beschreibt, **was** ein Ablauf
sehen und ändern darf, getrennt davon, **wo** die Daten liegen.

## Entscheid

Ein **Datenmodell** (`WorkflowEngine\Model\DataModel`) beschreibt eine Datenart: Name,
Bezeichnung, ID-Feld, Felder mit Typ (Text, Zahl, Ja/Nein, Datum, Zeitpunkt), Freigaben,
feste Teilmenge, Regeln und Persistenz. Eine **Persistenz**
(`Contracts\PersistenceInterface`) liest und schreibt; mitgeliefert sind Datenbank
(`PdoTablePersistence`) und Arbeitsspeicher (`InMemoryPersistence`), die REST-Persistenz
folgt in Phase 4. Die Engine spricht nur mit dem `DataGateway`, der genau vier Vorgänge kennt:
laden, suchen, ändern, anlegen.

Die Engine setzt das selbst um und bindet keine Bibliothek wie `atk4/data` ein. Übernommen
werden die Ideen, die die Engine braucht; die Abgrenzung steht in Tabelle 5.6 der
Anforderungen. Die wichtigsten Abweichungen:

| Agile Data | Engine |
| --- | --- |
| Felder schreibbar, bis man `readOnly` setzt | Felder schreibgeschützt, bis die Host-App sie freigibt |
| Model hält Beschreibung, Bedingungen und Datensatz zugleich | `DataModel` unveränderlich, `Record` ein eigener Wert |
| Löschen, Massenimport, Referenzen, Ausdrücke | gibt es nicht (F-36, Abgrenzung 6) |
| Hooks an vielen Stellen | eine Stelle: `RuleInterface::beforeSave()` |

Weitere Festlegungen:

- **Fehlerarten.** Ungültige Werte, verletzte Regeln, fehlende Freigaben und unbekannte
  Modelle tragen `PermanentFailureInterface`. Die Engine lässt den Durchlauf dann sofort
  fehlschlagen; eine Wiederholung würde nichts ändern. Technische Fehler der Persistenz
  werden weiter nach Q-01 wiederholt (F-132).
- **Nur Geändertes.** Der Gateway normalisiert alle Werte und vergleicht sie mit dem
  geladenen Stand; geschrieben wird nur, was sich geändert hat. «Nichts geändert» ist
  `written = false`, kein Fehler (F-123, F-38).
- **Prüfung beim Speichern.** `DataModelStepRule` prüft Modelle, Felder und Freigaben der
  Datenschritte schon beim Speichern einer Definition (F-118).
- **Kompatibilität.** Das Definitionsformat bleibt: `entity` ist der Modellname, also der
  bisherige Tabellenname (F-114). Die Schlüssel `values`, `wert`, `wenn`, `sonst`, `anlegen`
  und die Ergebnisse `<as>Found`, `<as>_<feld>`, `<as>Count` bleiben.
- **Ohne Zwischenspeicher.** Die `DataModelRegistry` fragt ihre Quellen bei jedem Zugriff;
  so gelten Änderungen an REST-Modellen sofort (F-141).

## Folgen

- Die vier Ports aus 2.x entfallen; das ist eine Port-Änderung und damit Teil der
  Hauptversion 3.0 (Q-10). Der Migrationsleitfaden für die Coach-Admin zeigt, wie ihre
  Tabellen-Listen zu Datenmodellen werden.
- Trigger, die mehrere Tabellen verknüpfen, bleiben eigene Klassen der Host-App; einfache
  Fälle deckt `ModelSearchTrigger` über die Suche ab.
- Neue Datenquellen sind eine neue Persistenz, keine Änderung an Aktionen oder Editor.
