import {
  BuilderModel,
  compileCondition,
  fromDefinition,
  orderedStepNames,
  parseCondition,
  removeStep,
  toDefinition,
} from './definition-mapping';

describe('definition-mapping', () => {
  describe('compileCondition', () => {
    it('quotes string values', () => {
      expect(compileCondition({ field: 'plan', op: '==', value: 'enterprise' })).toBe(
        "context['plan'] == 'enterprise'",
      );
    });

    it('maps Ja/Nein and true/false to booleans', () => {
      expect(compileCondition({ field: 'acceptedTerms', op: '==', value: 'Ja' })).toBe(
        "context['acceptedTerms'] == true",
      );
      expect(compileCondition({ field: 'x', op: '!=', value: 'false' })).toBe("context['x'] != false");
    });

    it('keeps numbers bare and returns true for empty field', () => {
      expect(compileCondition({ field: 'amount', op: '>', value: '1000' })).toBe(
        "context['amount'] > 1000",
      );
      expect(compileCondition({ field: '', op: '==', value: 'x' })).toBe('true');
    });
  });

  describe('parseCondition', () => {
    it('parses string, boolean and number expressions', () => {
      expect(parseCondition("context['plan'] == 'enterprise'")).toEqual({
        field: 'plan',
        op: '==',
        value: 'enterprise',
      });
      expect(parseCondition("context['acceptedTerms'] == true")).toEqual({
        field: 'acceptedTerms',
        op: '==',
        value: 'true',
      });
      expect(parseCondition("context['amount'] > 1000")).toEqual({ field: 'amount', op: '>', value: '1000' });
    });

    it('returns null for non-matching expressions', () => {
      expect(parseCondition("context['a'] and context['b']")).toBeNull();
      expect(parseCondition('true')).toBeNull();
    });
  });

  it('round-trips a model through toDefinition/fromDefinition', () => {
    const model: BuilderModel = {
      id: 'flow',
      name: 'Flow',
      startStep: 'ask',
      inputs: [],
      steps: [
        {
          name: 'ask',
          type: 'interactive',
          action: null,
          config: {},
          title: 'Frage',
          description: 'Bitte ausfüllen',
          fields: [{ name: 'ok', label: 'OK', type: 'boolean' }],
          pageTemplateId: '',
          publicVisible: null,
          delaySeconds: null,
          transitions: [
            {
              to: 'done',
              event: 'submit',
              mode: 'assistant',
              condition: { field: 'ok', op: '==', value: 'Ja' },
              raw: 'true',
              label: '',
            },
          ],
        },
        {
          name: 'done',
          type: 'automatic',
          action: null,
          config: {},
          title: '',
          description: '',
          fields: [],
          pageTemplateId: '',
          publicVisible: null,
          delaySeconds: null,
          transitions: [],
        },
      ],
    };

    const json = toDefinition(model);
    const steps = json['steps'] as Record<string, Record<string, unknown>>;
    expect((steps['ask']['transitions'] as Record<string, unknown>[])[0]).toEqual({
      to: 'done',
      event: 'submit',
      when: "context['ok'] == true",
    });
    expect((steps['ask']['ui'] as Record<string, unknown>)['events']).toEqual(['submit']);

    const restored = fromDefinition(json);
    expect(restored.startStep).toBe('ask');
    const t = restored.steps[0].transitions[0];
    expect(t.mode).toBe('assistant');
    expect(t.condition).toEqual({ field: 'ok', op: '==', value: 'true' });
  });

  it('round-trips an interactive page template reference (ui.templateId)', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'ask',
      steps: {
        ask: {
          type: 'interactive',
          ui: { title: 'Hi', events: ['submit'], templateId: 'welcome-page' },
          transitions: [{ to: 'ask', event: 'submit' }],
        },
      },
    });
    expect(model.steps[0].pageTemplateId).toBe('welcome-page');

    const ui = (toDefinition(model)['steps'] as Record<string, Record<string, unknown>>)['ask'][
      'ui'
    ] as Record<string, unknown>;
    expect(ui['templateId']).toBe('welcome-page');
  });

  it('omits ui.templateId when no page template is selected', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'ask',
      steps: { ask: { type: 'interactive', ui: { events: [] }, transitions: [] } },
    });
    const ui = (toDefinition(model)['steps'] as Record<string, Record<string, unknown>>)['ask'][
      'ui'
    ] as Record<string, unknown>;
    expect('templateId' in ui).toBeFalse();
  });

  it('keeps the handler of a file field across a load/save round trip', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'upload',
      steps: {
        upload: {
          type: 'interactive',
          ui: {
            fields: [{ name: 'zertifikat', label: 'Zertifikat', type: 'file', handler: 'uefa_certificate' }],
          },
          transitions: [],
        },
      },
    });
    expect(model.steps[0].fields[0].handler).toBe('uefa_certificate');

    const ui = (toDefinition(model)['steps'] as Record<string, Record<string, unknown>>)['upload'][
      'ui'
    ] as Record<string, unknown>;
    expect((ui['fields'] as Record<string, unknown>[])[0]['handler']).toBe('uefa_certificate');
  });

  it('drops the handler when the field is not a file field', () => {
    // Der Editor laesst den Typ umstellen; ein Handler an einem Textfeld
    // wirkte nirgends, bliebe aber als Altlast in der Definition stehen.
    const model = fromDefinition({
      id: 'f',
      startStep: 'ask',
      steps: {
        ask: {
          type: 'interactive',
          ui: { fields: [{ name: 'wert', label: 'Wert', type: 'text', handler: 'uefa_certificate' }] },
          transitions: [],
        },
      },
    });
    expect(model.steps[0].fields[0].handler).toBeUndefined();

    const ui = (toDefinition(model)['steps'] as Record<string, Record<string, unknown>>)['ask'][
      'ui'
    ] as Record<string, unknown>;
    expect('handler' in (ui['fields'] as Record<string, unknown>[])[0]).toBeFalse();
  });

  /**
   * Einen Schritt zu loeschen liess die Reihenfolge im Editor «zufaellig»
   * aussehen. Der Grund war keine Sortierung, sondern ein Abriss: die
   * Uebergaenge zeigten weiter auf den geloeschten Namen, die Kette brach
   * dort ab, und alles dahinter fiel in die Sammelstelle fuer unerreichbare
   * Schritte am Ende — in Einfuege- statt Ablaufreihenfolge.
   *
   * Loeschen heisst deshalb: die Kette schliessen, nicht nur ein Element
   * entfernen.
   */
  describe('removeStep', () => {
    function kette(): BuilderModel {
      return fromDefinition({
        id: 'f',
        startStep: 'a',
        steps: {
          a: { type: 'automatic', transitions: [{ to: 'b' }] },
          b: { type: 'automatic', transitions: [{ to: 'c' }] },
          c: { type: 'automatic', transitions: [{ to: 'd' }] },
          d: { type: 'automatic', transitions: [] },
        },
      });
    }

    it('bridges the gap so the chain stays intact', () => {
      const model = removeStep(kette(), 1); // b raus

      expect(model.steps.map((s) => s.name)).toEqual(['a', 'c', 'd']);
      expect(model.steps[0].transitions[0].to).toBe('c');
      // Der eigentliche Nachweis: die Ablaufreihenfolge stimmt noch. Vorher
      // stand hier ['a', 'c', 'd'] nur zufaellig — weil c und d als
      // unerreichbar hinten angehaengt wurden.
      expect(orderedStepNames(model)).toEqual(['a', 'c', 'd']);
    });

    it('keeps every remaining step reachable when the start step goes', () => {
      const model = removeStep(kette(), 0); // a raus

      expect(model.startStep).toBe('b');
      expect(orderedStepNames(model)).toEqual(['b', 'c', 'd']);
    });

    /**
     * Bei mehreren Ausgaengen gibt es kein eindeutiges Ersatzziel — eine
     * Verzweigung waere geraten. Dann bleiben die eingehenden Uebergaenge
     * nicht als Verweise ins Leere stehen (die Definition liesse sich nicht
     * mehr speichern), sondern fallen weg.
     */
    it('drops incoming transitions when the deleted step branched', () => {
      const model = removeStep(
        fromDefinition({
          id: 'f',
          startStep: 'a',
          steps: {
            a: { type: 'automatic', transitions: [{ to: 'gabel' }] },
            gabel: { type: 'automatic', transitions: [{ to: 'links' }, { to: 'rechts' }] },
            links: { type: 'automatic', transitions: [] },
            rechts: { type: 'automatic', transitions: [] },
          },
        }),
        1,
      );

      expect(model.steps[0].transitions).toEqual([]);
      // Kein Verweis auf einen Schritt, den es nicht mehr gibt.
      const namen = new Set(model.steps.map((s) => s.name));
      for (const step of model.steps) {
        for (const t of step.transitions) {
          expect(namen.has(t.to)).toBeTrue();
        }
      }
    });

    it('does not invent a self-loop when bridging would create one', () => {
      // a -> b -> a: waere b weg, zeigte a auf sich selbst. Eine Schleife, die
      // niemand angelegt hat, ist schlimmer als ein fehlender Uebergang.
      const model = removeStep(
        fromDefinition({
          id: 'f',
          startStep: 'a',
          steps: {
            a: { type: 'automatic', transitions: [{ to: 'b' }] },
            b: { type: 'automatic', transitions: [{ to: 'a' }] },
          },
        }),
        1,
      );

      expect(model.steps.map((s) => s.name)).toEqual(['a']);
      expect(model.steps[0].transitions).toEqual([]);
    });

    it('leaves the model alone for an index that does not exist', () => {
      const model = kette();
      expect(removeStep(model, 9)).toBe(model);
    });

    it('empties the start step when the last step goes', () => {
      const model = removeStep(
        fromDefinition({ id: 'f', startStep: 'a', steps: { a: { type: 'automatic', transitions: [] } } }),
        0,
      );

      expect(model.steps).toEqual([]);
      expect(model.startStep).toBe('');
    });
  });

  /**
   * `ui.public` entscheidet, ob ein Schritt auf der oeffentlichen Seite
   * erscheint. Der Vorgabefall ist «nur Eingabe-Schritte» und steht NICHT in
   * der Definition — sonst waere jede bestehende Definition beim naechsten
   * Speichern um ein Feld reicher, das nichts aendert.
   */
  describe('ui.public', () => {
    function uiVon(model: BuilderModel, step: string): Record<string, unknown> | undefined {
      const steps = toDefinition(model)['steps'] as Record<string, Record<string, unknown>>;
      return steps[step]['ui'] as Record<string, unknown> | undefined;
    }

    it('leaves ui.public out when the step follows the default', () => {
      const model = fromDefinition({
        id: 'f',
        startStep: 'a',
        steps: { a: { type: 'interactive', ui: { title: 'A' }, transitions: [] } },
      });

      expect(model.steps[0].publicVisible).toBeNull();
      expect('public' in (uiVon(model, 'a') ?? {})).toBeFalse();
    });

    it('round-trips an explicit show and an explicit hide', () => {
      const model = fromDefinition({
        id: 'f',
        startStep: 'zeigen',
        steps: {
          zeigen: { type: 'automatic', ui: { public: true, title: 'Wird geprüft' }, transitions: [] },
          verstecken: { type: 'interactive', ui: { public: false }, transitions: [] },
        },
      });

      expect(model.steps[0].publicVisible).toBeTrue();
      expect(model.steps[1].publicVisible).toBeFalse();
      expect(uiVon(model, 'zeigen')?.['public']).toBeTrue();
      expect(uiVon(model, 'verstecken')?.['public']).toBeFalse();
    });

    /**
     * Ein automatischer Schritt hatte bisher gar kein `ui` in der Definition.
     * Ohne diesen Fall waere «anzeigen» an ihm im Editor einstellbar, aber
     * nach dem Speichern wieder weg — die stille Sorte Fehler.
     */
    it('creates a ui block for an automatic step that opts in', () => {
      const model = fromDefinition({
        id: 'f',
        startStep: 'a',
        steps: { a: { type: 'automatic', transitions: [] } },
      });
      model.steps[0].publicVisible = true;

      expect(uiVon(model, 'a')?.['public']).toBeTrue();
    });

    it('keeps a heading on a visible background step', () => {
      // Ohne sie stand in der Checkliste der technische Schrittname — genau
      // das war auf dem Screenshot zu sehen.
      const model = fromDefinition({
        id: 'f',
        startStep: 'a',
        steps: { a: { type: 'automatic', ui: { public: true, title: 'Zertifikat hochladen' }, transitions: [] } },
      });

      expect(model.steps[0].title).toBe('Zertifikat hochladen');
      expect(uiVon(model, 'a')?.['title']).toBe('Zertifikat hochladen');
    });

    it('does not create a ui block for an automatic step on the default', () => {
      const model = fromDefinition({
        id: 'f',
        startStep: 'a',
        steps: { a: { type: 'automatic', transitions: [] } },
      });

      expect(uiVon(model, 'a')).toBeUndefined();
    });
  });

  /**
   * `ui.eventLabels` gibt den Knöpfen der öffentlichen Seite eine Aufschrift.
   * Ohne sie steht dort der rohe Ereignisname — bei «submit» faellt das nicht
   * auf, bei einem zweiten Ausgang «hilfe» sehr wohl.
   */
  describe('ui.eventLabels', () => {
    function uiVon(model: BuilderModel, step: string): Record<string, unknown> {
      const steps = toDefinition(model)['steps'] as Record<string, Record<string, unknown>>;
      return (steps[step]['ui'] ?? {}) as Record<string, unknown>;
    }

    function schrittMitAusgaengen(labels?: Record<string, string>): BuilderModel {
      return fromDefinition({
        id: 'f',
        startStep: 'frage',
        steps: {
          frage: {
            type: 'interactive',
            ui: labels === undefined ? {} : { eventLabels: labels },
            transitions: [
              { to: 'weiter', event: 'submit' },
              { to: 'hilfe_schritt', event: 'hilfe' },
            ],
          },
          weiter: { type: 'automatic', transitions: [] },
          hilfe_schritt: { type: 'automatic', transitions: [] },
        },
      });
    }

    it('hangs the labels on the transitions and writes them back', () => {
      const model = schrittMitAusgaengen({ hilfe: 'Ich komme nicht weiter' });

      expect(model.steps[0].transitions[0].label).toBe('');
      expect(model.steps[0].transitions[1].label).toBe('Ich komme nicht weiter');
      expect(uiVon(model, 'frage')['eventLabels']).toEqual({ hilfe: 'Ich komme nicht weiter' });
    });

    it('writes no eventLabels at all when none are set', () => {
      // Sonst waere jede bestehende Definition beim naechsten Speichern um ein
      // leeres Objekt reicher.
      expect('eventLabels' in uiVon(schrittMitAusgaengen(), 'frage')).toBeFalse();
    });

    it('keeps one label per event even if two transitions share it', () => {
      const model = fromDefinition({
        id: 'f',
        startStep: 'frage',
        steps: {
          frage: {
            type: 'interactive',
            ui: { eventLabels: { submit: 'Absenden' } },
            transitions: [
              { to: 'a', event: 'submit', when: "context['x'] == 'y'" },
              { to: 'b', event: 'submit' },
            ],
          },
          a: { type: 'automatic', transitions: [] },
          b: { type: 'automatic', transitions: [] },
        },
      });

      // Zwei Uebergaenge, ein Ereignis — und damit ein Knopf, nicht zwei.
      expect(uiVon(model, 'frage')['eventLabels']).toEqual({ submit: 'Absenden' });
    });
  });

  /**
   * Der deklarierte Startkontext (`inputs`). Ohne ihn steht nirgends, was ein
   * Ablauf beim Start braucht — und fehlt etwas, lief er bisher trotzdem an.
   */
  describe('inputs', () => {
    it('reads a declaration with all its properties', () => {
      const model = fromDefinition({
        id: 'f',
        startStep: 'a',
        inputs: [
          { name: 'trainer_id', label: 'Trainer-ID', required: true, beispiel: 'TR-0123' },
          { name: 'kjs_mail' },
        ],
        steps: { a: { type: 'automatic', transitions: [] } },
      });

      expect(model.inputs[0]).toEqual({
        name: 'trainer_id', label: 'Trainer-ID', required: true, beispiel: 'TR-0123',
      });
      // Ohne Angabe: Label faellt auf den Namen zurueck, Pflicht ist aus.
      expect(model.inputs[1]).toEqual({ name: 'kjs_mail', label: 'kjs_mail', required: false, beispiel: '' });
    });

    it('writes the declaration back', () => {
      const model = fromDefinition({
        id: 'f',
        startStep: 'a',
        inputs: [{ name: 'mail', label: 'E-Mail', required: true, beispiel: 'a@b.ch' }],
        steps: { a: { type: 'automatic', transitions: [] } },
      });

      expect(toDefinition(model)['inputs']).toEqual([
        { name: 'mail', label: 'E-Mail', required: true, beispiel: 'a@b.ch' },
      ]);
    });

    it('writes no inputs key when nothing is declared', () => {
      // Sonst waere jede bestehende Definition beim naechsten Speichern um ein
      // leeres Feld reicher.
      const model = fromDefinition({
        id: 'f', startStep: 'a', steps: { a: { type: 'automatic', transitions: [] } },
      });

      expect(model.inputs).toEqual([]);
      expect('inputs' in toDefinition(model)).toBeFalse();
    });

    it('drops a nameless row', () => {
      // Der Editor legt eine leere Zeile an, sobald jemand «+ Angabe» drueckt.
      // Sie darf keinen Start blockieren.
      const model = fromDefinition({
        id: 'f', startStep: 'a', steps: { a: { type: 'automatic', transitions: [] } },
      });
      model.inputs = [
        { name: '  ', label: 'leer', required: true, beispiel: '' },
        { name: 'mail', label: '', required: false, beispiel: '' },
      ];

      expect(toDefinition(model)['inputs']).toEqual([
        { name: 'mail', label: 'mail', required: false, beispiel: '' },
      ]);
    });
  });

  it('orders steps breadth-first from the start step', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'a',
      steps: {
        a: { type: 'automatic', transitions: [{ to: 'b' }] },
        b: { type: 'automatic', transitions: [] },
        orphan: { type: 'automatic', transitions: [] },
      },
    });
    expect(orderedStepNames(model)).toEqual(['a', 'b', 'orphan']);
  });

  /**
   * GEMELDET: nach Abschluss eines Kind-Workflows wurde der nächste Schritt
   * im Eltern-Ablauf übersprungen.
   *
   * Der Übergang aus dem Workflow-Schritt trug ein `"event": "submit"` — ein
   * Rest davon, dass der Schritt vorher interaktiv war. Ein automatischer
   * Schritt bekommt nie einen Knopfdruck; die Engine fand keinen Weg hinaus
   * und hielt das für das Ende des Ablaufs.
   *
   * Das Feld ist im Editor bei automatischen Schritten gar nicht sichtbar —
   * genau deshalb muss das Speichern es entfernen: sonst schleppt die
   * Definition eine Einstellung mit, die niemand mehr sehen oder ändern kann.
   */
  it('schreibt kein Ereignis an einen Übergang aus einem automatischen Schritt', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'a',
      steps: {
        a: {
          type: 'automatic',
          action: 'start_workflow',
          config: { workflowId: 'kind', waitForCompletion: true },
          transitions: [{ to: 'b', event: 'submit' }],
        },
        b: { type: 'automatic', transitions: [] },
      },
    });

    const json = toDefinition(model) as Record<string, any>;

    expect(json['steps']['a']['transitions'][0]['event']).toBeUndefined();
    expect(json['steps']['a']['transitions'][0]['to']).toBe('b');
  });

  /** Die Gegenprobe: beim interaktiven Schritt bleibt es natürlich stehen. */
  it('behält das Ereignis am interaktiven Schritt', () => {
    const model = fromDefinition({
      id: 'f',
      startStep: 'a',
      steps: {
        a: { type: 'interactive', transitions: [{ to: 'b', event: 'submit' }] },
        b: { type: 'automatic', transitions: [] },
      },
    });

    const json = toDefinition(model) as Record<string, any>;

    expect(json['steps']['a']['transitions'][0]['event']).toBe('submit');
  });
});
