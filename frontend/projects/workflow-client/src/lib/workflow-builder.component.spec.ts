import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { WORKFLOW_API_BASE_URL } from './workflow.config';
import { fromDefinition } from './definition-mapping';
import { WorkflowBuilderComponent } from './workflow-builder.component';

describe('WorkflowBuilderComponent', () => {
  let fixture: ComponentFixture<WorkflowBuilderComponent>;
  let component: WorkflowBuilderComponent;
  let httpMock: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [WorkflowBuilderComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: WORKFLOW_API_BASE_URL, useValue: '' },
      ],
    });
    fixture = TestBed.createComponent(WorkflowBuilderComponent);
    component = fixture.componentInstance;
    httpMock = TestBed.inject(HttpTestingController);

    fixture.detectChanges();
    httpMock.expectOne('/workflows').flush({ definitions: [] });
    httpMock.expectOne('/actions').flush({
      actions: [
        {
          key: 'send_email',
          config: [
            { name: 'to', label: 'An', type: 'text' },
            { name: 'subject', label: 'Betreff', type: 'text' },
            { name: 'body', label: 'Text', type: 'textarea' },
          ],
        },
      ],
    });
    httpMock.expectOne('/upload-handlers').flush({ handlers: [] });
    httpMock.expectOne('/templates').flush({ templates: [] });
    httpMock.expectOne('/data-catalog').flush({
      entities: [{ entity: 'order', label: 'Bestellung', fields: ['id', 'status', 'total'] }],
    });
    // Eigene, engere Liste: der Schreib-Schritt darf an `order` nur `status`
    // und `bemerkung` anfassen — `id` und `total` stehen dort nicht zur Wahl.
    httpMock.expectOne('/data-catalog/writable').flush({
      entities: [{ entity: 'order', label: 'Bestellung', fields: ['status', 'bemerkung'] }],
    });
  });

  afterEach(() => httpMock.verify());

  it('builds a definition and saves it', () => {
    component.newDefinition();
    component.model.set({ ...component.model(), id: 'flow', name: 'Mein Flow' });
    component.addStep('automatic');

    component.save();

    const req = httpMock.expectOne('/workflows/flow');
    expect(req.request.method).toBe('POST');
    expect(req.request.body.name).toBe('Mein Flow');
    expect(req.request.body.status).toBe('active');
    const steps = req.request.body.definition.steps as Record<string, unknown>;
    expect(Object.keys(steps).length).toBe(1);
    req.flush({ id: 'flow', version: 1, active: true, status: 'active' });

    httpMock.expectOne('/workflows').flush({ definitions: [] });
    expect(component.message()).toContain('v1');
    expect(component.error()).toBeNull();
  });

  it('saves as a draft with the chosen status', () => {
    component.newDefinition();
    component.model.set({ ...component.model(), id: 'flow', name: 'Flow' });
    component.addStep('automatic');
    component.status.set('draft');

    component.save();

    const req = httpMock.expectOne('/workflows/flow');
    expect(req.request.body.status).toBe('draft');
    req.flush({ id: 'flow', version: 1, active: false, status: 'draft' });
    httpMock.expectOne('/workflows').flush({ definitions: [] });
    expect(component.message()).toContain('Entwurf');
  });

  it('adopts the status of the loaded definition', () => {
    component.definitions.set([
      { id: 'flow', version: 3, name: 'Flow', active: true, status: 'draft', instances: 0, runningInstances: 0 },
    ]);

    component.loadDefinition('flow');
    httpMock.expectOne('/workflows/flow').flush({ id: 'flow', definition: { id: 'flow', startStep: '', steps: {} } });

    expect(component.status()).toBe('draft');
  });

  /**
   * Kopfleiste: ID, Name und Version stehen nebeneinander.
   *
   * Die Version stand vorher nirgends — eine geladene Definition sah aus wie
   * ein frischer Entwurf, und die Nummer tauchte erst in der Meldung nach dem
   * Speichern auf.
   */
  it('shows the version of the loaded definition in the header', () => {
    component.loadDefinition('flow');
    httpMock.expectOne('/workflows/flow').flush({
      id: 'flow',
      definition: { id: 'flow', version: 4, startStep: '', steps: {} },
    });
    fixture.detectChanges();

    expect(component.loadedVersion()).toBe(4);
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.wfb__bar-version')?.textContent?.trim(),
    ).toBe('v4');
  });

  it('marks an unsaved draft as new instead of showing a version', () => {
    component.newDefinition();
    fixture.detectChanges();

    expect(component.loadedVersion()).toBeNull();
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.wfb__bar-version')?.textContent?.trim(),
    ).toBe('neu');
  });

  /** Nach dem Speichern gilt die neue Fassung, ohne dass man neu laden muss. */
  it('takes over the version returned by save()', () => {
    component.model.set({ ...component.model(), id: 'flow', startStep: 'a' });
    component.save();

    httpMock.expectOne('/workflows/flow').flush({ id: 'flow', version: 7, active: true, status: 'active' });
    httpMock.expectOne('/workflows').flush({ definitions: [] });

    expect(component.loadedVersion()).toBe(7);
  });

  it('shows the JSON view of the current model', () => {
    component.model.set({ ...component.model(), id: 'flow', startStep: 'a' });
    component.showJson();

    expect(component.viewMode()).toBe('json');
    expect(component.jsonText()).toContain('"startStep"');
  });

  it('reports a server validation error', () => {
    component.model.set({ ...component.model(), id: 'broken' });
    component.save();

    httpMock.expectOne('/workflows/broken').flush(
      { error: { code: 'invalid_definition', message: "unbekanntes Ziel 'ghost'" } },
      { status: 400, statusText: 'Bad Request' },
    );

    expect(component.error()).toContain('ghost');
  });

  it('lazily loads a template for the inline preview', () => {
    expect(component.templatePreview('welcome')).toBeNull();

    httpMock.expectOne('/templates/welcome').flush({
      id: 'welcome',
      name: 'Willkommen',
      subject: 'Hallo',
      body: '<p>Hi</p>',
    });

    const cached = component.templatePreview('welcome');
    expect(cached?.subject).toBe('Hallo');

    // Zweiter Zugriff löst keinen weiteren Request aus (Cache).
    httpMock.expectNone('/templates/welcome');
  });

  it('returns null for an empty template id without a request', () => {
    expect(component.templatePreview('')).toBeNull();
    httpMock.expectNone('/templates/');
  });

  /**
   * Das Archiv trennt zwei Fragen, die man leicht in eine wirft: was ist noch
   * in Gebrauch, und was darf weg?
   */
  describe('Kontext-Variablen', () => {
    /**
     * GEMELDET: «was zusätzlich noch fehlt ist die Darstellung der
     * Context-Variablen.»
     *
     * Die Liste sammelte nur die Eingabefelder. Damit fehlten genau die
     * Schlüssel, die man am häufigsten braucht — der deklarierte Startkontext
     * und die Ergebnisse eines Datenchecks. Wer sie benutzen wollte, musste
     * sie auswendig kennen, und ein Tippfehler fiel erst auf, wenn eine Mail
     * an eine leere Adresse ging.
     */
    it('nennt Startkontext, Eingabefelder und die Ergebnisse eines Datenchecks', () => {
      component.model.set(
        fromDefinition({
          id: 'flow',
          startStep: 'laden',
          inputs: [{ name: 'trainer_id', required: true }],
          steps: {
            laden: {
              type: 'automatic',
              action: 'check_data',
              config: { entity: 'order', id: '{{trainer_id}}', as: 'p', fields: ['status', 'total'] },
              transitions: [{ to: 'fragen' }],
            },
            fragen: {
              type: 'interactive',
              ui: {
                fields: [
                  { name: 'p_status', label: 'Status', type: 'display' },
                  { name: 'bemerkung', label: 'Bemerkung', type: 'text' },
                ],
              },
              transitions: [{ to: 'ende', event: 'submit' }],
            },
            ende: { type: 'automatic', transitions: [] },
          },
        }),
      );

      const namen = component.kontextVariablen().map((v) => v.name);

      expect(namen).toContain('trainer_id');
      expect(namen).toContain('p');
      expect(namen).toContain('pFound');
      expect(namen).toContain('p_status');
      expect(namen).toContain('p_total');
      expect(namen).toContain('bemerkung');
    });

    /**
     * Ein Anzeigefeld ZEIGT einen Wert, es erzeugt keinen. Zählte es mit,
     * bestätigte sich eine Definition selbst: das Feld zeigt {{p_mail}}, und
     * {{p_mail}} gilt als vorhanden, weil das Feld es zeigt.
     */
    it('zählt ein Anzeigefeld nicht als Quelle', () => {
      component.model.set(
        fromDefinition({
          id: 'flow',
          startStep: 'fragen',
          inputs: [{ name: 'trainer_id' }],
          steps: {
            fragen: {
              type: 'interactive',
              ui: { fields: [{ name: 'nirgends_erzeugt', label: 'X', type: 'display' }] },
              transitions: [{ to: 'ende', event: 'submit' }],
            },
            ende: { type: 'automatic', transitions: [] },
          },
        }),
      );

      expect(component.kontextVariablen().map((v) => v.name)).not.toContain('nirgends_erzeugt');
    });

    /** Jede Variable sagt, woher sie kommt — sonst ist die Liste nur ein Haufen Namen. */
    it('nennt zu jeder Variable ihre Herkunft', () => {
      component.model.set(
        fromDefinition({
          id: 'flow',
          startStep: 'ende',
          inputs: [{ name: 'trainer_id' }],
          steps: { ende: { type: 'automatic', transitions: [] } },
        }),
      );

      expect(component.kontextVariablen()[0]).toEqual({ name: 'trainer_id', herkunft: 'Startkontext' });
    });
  });

  describe('Archiv', () => {
    /** Baut eine Zeile der Uebersicht. */
    function zeile(id: string, version: number, instances = 0, runningInstances = 0) {
      return { id, version, name: id, active: false, status: 'active' as const, instances, runningInstances };
    }

    it('haelt nur die neueste Version in der Hauptliste', () => {
      component.definitions.set([zeile('flow', 1), zeile('flow', 2), zeile('flow', 3)]);

      expect(component.aktuelleDefinitionen().map((d) => d.version)).toEqual([3]);
      expect(component.archivierteDefinitionen().map((d) => d.version)).toEqual([2, 1]);
    });

    it('laesst eine alte Version mit laufendem Durchlauf oben stehen', () => {
      // Sie ist nicht mehr die aktuelle, aber in Gebrauch — im Archiv waere
      // sie am falschen Ort.
      component.definitions.set([zeile('flow', 1, 5, 2), zeile('flow', 2)]);

      expect(component.aktuelleDefinitionen().map((d) => d.version)).toEqual([1, 2]);
      expect(component.archivierteDefinitionen()).toEqual([]);
    });

    it('sperrt das Loeschen, solange ein abgeschlossener Durchlauf verweist', () => {
      const mitVerlauf = zeile('flow', 1, 3, 0);
      const ohne = zeile('flow', 2, 0, 0);

      expect(component.loeschsperre(mitVerlauf)).toContain('3 abgeschlossene');
      expect(component.loeschsperre(ohne)).toBe('');
    });

    it('formuliert den einen Durchlauf im Singular', () => {
      expect(component.loeschsperre(zeile('flow', 1, 1, 0))).toContain('Ein abgeschlossener');
    });

    it('loescht eine freie Version und laedt die Liste neu', () => {
      component.definitions.set([zeile('flow', 1), zeile('flow', 2)]);

      component.deleteVersion(zeile('flow', 1));

      const req = httpMock.expectOne('/workflows/flow/versions/1');
      expect(req.request.method).toBe('DELETE');
      req.flush(null, { status: 204, statusText: 'No Content' });
      httpMock.expectOne('/workflows').flush({ definitions: [] });

      expect(component.message()).toContain('v1');
    });

    it('schickt gar keine Anfrage, wenn die Version gesperrt ist', () => {
      // Die Sperre steht im Knopf — aber sie muss auch dann halten, wenn
      // jemand die Methode direkt aufruft. httpMock.verify() im afterEach
      // meldet eine Anfrage, die trotzdem hinausginge.
      component.deleteVersion(zeile('flow', 1, 2, 0));

      expect(component.message()).toBeNull();
    });
  });

  it('lists distinct workflow options for the workflow-ref field', () => {
    component.definitions.set([
      { id: 'a', version: 1, name: 'Alpha alt', active: false, status: 'active', instances: 0, runningInstances: 0 },
      { id: 'a', version: 2, name: 'Alpha', active: true, status: 'active', instances: 0, runningInstances: 0 },
      { id: 'b', version: 1, name: 'Beta', active: true, status: 'active', instances: 0, runningInstances: 0 },
    ]);

    const options = component.workflowOptions();
    expect(options).toEqual([
      { id: 'a', name: 'Alpha' },
      { id: 'b', name: 'Beta' },
    ]);
  });

  it('hides subject/body once an email template is selected', () => {
    component.addStep('automatic');
    const step = component.model().steps[0];
    const subject = { name: 'subject', label: 'Betreff', type: 'text' };
    const to = { name: 'to', label: 'An', type: 'text' };

    expect(component.isFieldHiddenByTemplate(step, subject)).toBeFalse();

    component.setConfig(step, 'templateId', 'welcome');
    expect(component.isFieldHiddenByTemplate(step, subject)).toBeTrue();
    expect(component.isFieldHiddenByTemplate(step, { name: 'body', label: 'Inhalt', type: 'html' })).toBeTrue();
    // Andere Felder (z. B. Empfänger) bleiben sichtbar.
    expect(component.isFieldHiddenByTemplate(step, to)).toBeFalse();
  });

  it('adds a data-check step and lists fields of the chosen entity', () => {
    component.newDefinition();
    component.addDataCheckStep();
    const step = component.model().steps[0];

    expect(component.isDataCheckStep(step)).toBeTrue();
    expect(component.stepKind(step)).toBe('datacheck');
    expect(step.action).toBe('check_data');

    // Ohne gewählte Tabelle keine Felder; nach Auswahl die Katalog-Felder.
    expect(component.entityFields(step)).toEqual([]);
    component.setConfig(step, 'entity', 'order');
    expect(component.entityFields(step)).toEqual(['id', 'status', 'total']);
  });

  it('reads and writes a boolean config value', () => {
    component.addStep('automatic');
    const step = component.model().steps[0];
    expect(component.configBool(step, 'waitForCompletion')).toBeFalse();

    component.setConfigBool(step, 'waitForCompletion', true);
    expect(component.configBool(step, 'waitForCompletion')).toBeTrue();
    expect(step.config['waitForCompletion']).toBe(true);
  });

  /**
   * Der Schreib-Schritt ist das Gegenstück zum Datencheck: dieselbe
   * Konstruktion (automatic + eine eingebaute Aktion, im Builder eine eigene
   * Karte), nur in die andere Richtung.
   */
  describe('Schreib-Schritt', () => {
    /**
     * Eine Definition, wie der Builder sie speichert. `values` wird nur
     * geschrieben, wenn es Werte gibt — ein leeres Feld löscht `schreibeMap`,
     * es kommt also in einer gespeicherten Definition gar nicht vor. Stünde
     * hier trotzdem `values: {}`, prüften die Tests unten einen Zustand, den
     * es nicht gibt.
     */
    function schreibSchritt(values: Record<string, string>, as?: string) {
      const config: Record<string, unknown> = { entity: 'order', id: '{{orderId}}' };
      if (Object.keys(values).length > 0) {
        config['values'] = values;
      }
      if (as) {
        config['as'] = as;
      }
      component.model.set(
        fromDefinition({
          id: 'flow',
          startStep: 'speichern',
          steps: {
            speichern: { type: 'automatic', action: 'write_data', config, transitions: [] },
          },
        }),
      );
      return component.model().steps[0];
    }

    it('zählt als eigene Schritt-Art', () => {
      const step = schreibSchritt({ status: 'bezahlt' });

      expect(component.stepKind(step)).toBe('datawrite');
      expect(component.kindLabel(step)).toBe('Daten schreiben');
    });

    /**
     * Die Auswahl kommt aus dem SCHREIB-Katalog, nicht aus dem Lese-Katalog.
     * Stünde dort `total`, liesse sich eine Spalte wählen, die der Server
     * beim Ausführen abweist — und der Fehler fiele erst im Log auf.
     */
    it('bietet nur beschreibbare Spalten an', () => {
      const step = schreibSchritt({ status: 'bezahlt' });

      expect(component.writeEntityFields(step)).toEqual(['status', 'bemerkung']);
      expect(component.entityFields(step)).toContain('total');
    });

    it('nennt sein Ergebnis in den Kontext-Variablen', () => {
      schreibSchritt({ status: 'bezahlt' }, 'gespeichert');

      const namen = component.kontextVariablen().map((v) => v.name);

      expect(namen).toContain('gespeichert');
      expect(namen).toContain('gespeichertCount');
    });

    it('nimmt ohne Ergebnis-Variable den Vorgabe-Namen', () => {
      schreibSchritt({ status: 'bezahlt' });

      const namen = component.kontextVariablen().map((v) => v.name);

      expect(namen).toContain('written');
      expect(namen).toContain('writtenCount');
    });

    /**
     * Die Reihenfolge folgt der Tabelle, nicht der des Anklickens — sonst sähe
     * dieselbe Auswahl je nach Bedienung anders aus und erzeugte einen Diff,
     * der nichts bedeutet.
     */
    it('ordnet die Spalten wie die Tabelle, nicht wie das Anklicken', () => {
      const step = schreibSchritt({});

      component.toggleConfigMap(step, 'values', 'bemerkung', true);
      component.toggleConfigMap(step, 'values', 'status', true);
      component.setConfigMapValue(step, 'values', 'status', 'bezahlt');

      expect(Object.keys(step.config['values'] as object)).toEqual(['status', 'bemerkung']);
      expect(component.configMapValue(step, 'values', 'status')).toBe('bezahlt');
    });

    /**
     * Ein Wert zu einer nicht angehakten Spalte entsteht nicht nebenbei: erst
     * anhaken, dann schreiben. Sonst stünde in der Definition eine Spalte, die
     * in der Oberfläche gar nicht gewählt ist.
     */
    it('schreibt keinen Wert zu einer nicht gewählten Spalte', () => {
      const step = schreibSchritt({});

      component.setConfigMapValue(step, 'values', 'status', 'bezahlt');

      expect(component.configMap(step, 'values')).toEqual({});
      expect(step.config['values']).toBeUndefined();
    });

    /** Die letzte Spalte abwählen entfernt `values` ganz — ein leeres Feld sähe aus wie eine Einstellung. */
    it('entfernt die Angabe, wenn keine Spalte übrig ist', () => {
      const step = schreibSchritt({ status: 'bezahlt' });

      component.toggleConfigMap(step, 'values', 'status', false);

      expect(step.config['values']).toBeUndefined();
    });

    /**
     * GEMELDET: `verhaltenskodex_gelesen` ist ein Ja/Nein-Wert, und daraus soll
     * «unterzeichnet» werden — oder eben nichts.
     */
    it('schreibt eine Bedingung in der langen Form', () => {
      const step = schreibSchritt({ status: 'bezahlt' });

      component.toggleBedingung(step, 'values', 'status', true);
      component.setConfigMapTeil(step, 'values', 'status', 'wenn', "context['gelesen'] == true");

      expect(step.config['values']).toEqual({
        status: { wert: 'bezahlt', wenn: "context['gelesen'] == true" },
      });
      expect(component.hatBedingung(step, 'values', 'status')).toBe(true);
    });

    /**
     * Ohne Bedingung bleibt die kurze Form. Die lange nur dort zu schreiben, wo
     * sie etwas bedeutet, hält die Definition lesbar und den Diff klein.
     */
    it('bleibt ohne Bedingung bei der kurzen Form', () => {
      const step = schreibSchritt({});

      component.toggleConfigMap(step, 'values', 'status', true);
      component.setConfigMapValue(step, 'values', 'status', 'bezahlt');

      expect(step.config['values']).toEqual({ status: 'bezahlt' });
    });

    /** Die Bedingung abschalten wirft `wenn` und `sonst` weg, der Wert bleibt. */
    it('nimmt beim Abschalten die Bedingung weg und lässt den Wert stehen', () => {
      const step = schreibSchritt({});
      component.toggleConfigMap(step, 'values', 'status', true);
      component.setConfigMapValue(step, 'values', 'status', 'bezahlt');
      component.toggleBedingung(step, 'values', 'status', true);
      component.setConfigMapTeil(step, 'values', 'status', 'sonst', '');

      component.toggleBedingung(step, 'values', 'status', false);

      expect(step.config['values']).toEqual({ status: 'bezahlt' });
      expect(component.hatBedingung(step, 'values', 'status')).toBe(false);
    });

    /** Eine Definition, die schon die lange Form trägt, wird gelesen wie sie ist. */
    it('liest eine vorhandene lange Form', () => {
      component.model.set(
        fromDefinition({
          id: 'flow',
          startStep: 'speichern',
          steps: {
            speichern: {
              type: 'automatic',
              action: 'write_data',
              config: {
                entity: 'order',
                id: '7',
                values: { status: { wert: 'bezahlt', wenn: 'true', sonst: '' } },
              },
              transitions: [],
            },
          },
        }),
      );
      const step = component.model().steps[0];

      expect(component.configMapValue(step, 'values', 'status')).toBe('bezahlt');
      expect(component.configMapTeil(step, 'values', 'status', 'wenn')).toBe('true');
      expect(component.hatBedingung(step, 'values', 'status')).toBe(true);
    });

    it('setzt beim Umschalten der Art die Aktion und nimmt sie wieder weg', () => {
      const step = schreibSchritt({ status: 'bezahlt' });

      component.setKind(step, 'timer');
      expect(step.action).toBeNull();

      component.setKind(step, 'datawrite');
      expect(step.type).toBe('automatic');
      expect(step.action).toBe('write_data');
    });
  });
  /**
   * Der gemeldete Ablauf, so wie er im Editor stand.
   *
   * GEMELDET, an zwei Stellen: «am Ende der ersten Zeile fehlt …» und «done
   * kommt nach notify_complete als letzter Schritt». Die erste Verzweigung
   * endete an ihrer Zusammenfuehrung, ohne Hinweis darauf, dass es weitergeht;
   * `done` stand in einer eigenen Reihe darunter, ohne Pfeil, ohne Verbindung
   * — als haette es mit dem Ablauf nichts zu tun.
   */
  describe('flow(): was nach der Zusammenfuehrung kommt', () => {
    function gemeldeterAblauf(): void {
      component.model.set(
        fromDefinition({
          id: 'onboarding',
          startStep: 'daten_laden',
          steps: {
            daten_laden: { type: 'automatic', action: 'check_data', transitions: [{ to: 'daten_bestaetigen' }] },
            daten_bestaetigen: {
              type: 'interactive',
              transitions: [
                { to: 'leitbild_verhaltenskodex', when: "context['daten_korrekt'] == true" },
                { to: 'daten_korrigieren' },
              ],
            },
            daten_korrigieren: { type: 'interactive', transitions: [{ to: 'korrektur_melden' }] },
            korrektur_melden: { type: 'automatic', transitions: [{ to: 'leitbild_verhaltenskodex' }] },
            leitbild_verhaltenskodex: {
              type: 'automatic',
              action: 'start_workflow',
              transitions: [{ to: 'nachweise' }],
            },
            nachweise: {
              type: 'interactive',
              transitions: [
                { to: 'upload_uefa_certificate', when: "context['uefa_webinar'] == true" },
                { to: 'notify_complete' },
              ],
            },
            upload_uefa_certificate: {
              type: 'automatic',
              action: 'start_workflow',
              transitions: [{ to: 'notify_complete' }],
            },
            notify_complete: { type: 'automatic', transitions: [{ to: 'done' }] },
            done: { type: 'automatic', transitions: [] },
          },
        }),
      );
    }

    it('haengt done an notify_complete statt in eine eigene Reihe', () => {
      gemeldeterAblauf();
      const abschnitte = component.flow();
      const zweiter = abschnitte[1];

      expect(zweiter.kind).toBe('fork');
      if (zweiter.kind !== 'fork') {
        return;
      }
      expect(zweiter.from).toBe('nachweise');
      expect(zweiter.merge).toBe('notify_complete');
      expect(zweiter.tail).toEqual(['done']);
      // Und keine Reihe mehr dahinter, die dasselbe noch einmal zeigt.
      expect(abschnitte.length).toBe(2);
    });

    it('markiert das Ende der ersten Zeile, weil es dort weitergeht', () => {
      gemeldeterAblauf();
      const erster = component.flow()[0];

      expect(erster.kind).toBe('fork');
      if (erster.kind !== 'fork') {
        return;
      }
      expect(erster.from).toBe('daten_bestaetigen');
      // Der Startschritt steht im Bild, nicht in einer Reihe darueber.
      expect(erster.prev).toBe('daten_laden');
      expect(erster.merge).toBe('leitbild_verhaltenskodex');
      // Danach verzweigt es gleich wieder — eigenes Bild, also «…».
      expect(erster.continues).toBeTrue();
      expect(erster.tail).toEqual([]);
    });

    /** Endet der Ablauf an der Zusammenfuehrung, gibt es nichts anzudeuten. */
    it('setzt keine Marke, wenn nach der Zusammenfuehrung Schluss ist', () => {
      component.model.set(
        fromDefinition({
          id: 'kurz',
          startStep: 'a',
          steps: {
            a: { type: 'automatic', transitions: [{ to: 'b', when: "context['x'] == 1" }, { to: 'c' }] },
            b: { type: 'automatic', transitions: [{ to: 'ende' }] },
            c: { type: 'automatic', transitions: [{ to: 'ende' }] },
            ende: { type: 'automatic', transitions: [] },
          },
        }),
      );

      const erster = component.flow()[0];
      expect(erster.kind === 'fork' && erster.continues).toBeFalse();
      expect(erster.kind === 'fork' ? erster.tail : null).toEqual([]);
    });
  });

});
