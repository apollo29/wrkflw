import {
  connectionDraft,
  fieldFromSample,
  modelDraft,
  sampleNodes,
  toConnectionInput,
  toRestModel,
  toTrigger,
  triggerDraft,
  validateConnection,
  validateModel,
  validateTrigger,
} from './data-source-forms';

describe('data source forms', () => {
  describe('connections', () => {
    it('maps a stored connection without any secret', () => {
      const d = connectionDraft({
        id: 'antrag-api',
        name: 'Antrags-API',
        baseUrl: 'https://api.example.org',
        authType: 'bearer',
        authHeader: null,
        secretStored: true,
      });
      expect(d).toMatchObject({
        id: 'antrag-api',
        authType: 'bearer',
        secret: '',
        user: '',
        password: '',
      });
    });

    it('sends the secret only when one was entered; basic joins user and password', () => {
      const base = connectionDraft(null);
      expect(
        toConnectionInput({
          ...base,
          id: 'a',
          name: 'A',
          baseUrl: ' https://x.org ',
          authType: 'bearer',
          secret: 't',
        }),
      ).toEqual({
        id: 'a',
        name: 'A',
        baseUrl: 'https://x.org',
        authType: 'bearer',
        secret: 't',
      });
      expect(
        toConnectionInput({
          ...base,
          id: 'a',
          name: 'A',
          baseUrl: 'https://x.org',
          authType: 'basic',
          user: 'u',
          password: 'p',
        }).secret,
      ).toBe('u:p');
      expect(
        'secret' in
          toConnectionInput({
            ...base,
            id: 'a',
            name: 'A',
            baseUrl: 'https://x.org',
            authType: 'none',
            secret: 'x',
          }),
      ).toBe(false);
      expect(
        toConnectionInput({
          ...base,
          id: 'a',
          name: 'A',
          baseUrl: 'https://x.org',
          authType: 'api_key',
          authHeader: 'X-Key',
        }).authHeader,
      ).toBe('X-Key');
    });

    it('checks ID, name, https and the header for API keys', () => {
      const base = connectionDraft(null);
      expect(
        validateConnection({
          ...base,
          id: 'A B',
          name: '',
          baseUrl: 'http://x.org',
          authType: 'api_key',
        }),
      ).toEqual({
        id: 'sources.id.invalid',
        name: 'sources.name.missing',
        baseUrl: 'sources.connection.https',
        authHeader: 'sources.connection.header',
      });
      expect(
        validateConnection({ ...base, id: 'ok', name: 'Ok', baseUrl: 'https://x.org' }),
      ).toEqual({});
    });
  });

  describe('models', () => {
    const model = {
      name: 'antrag',
      label: 'Anträge',
      connection: 'antrag-api',
      idField: 'id',
      listPath: 'data.items',
      subset: { archiviert: 'false' },
      operations: {
        load: { method: 'GET', path: '/antraege/{id}' },
        find: { method: 'GET', path: '/antraege' },
      },
      fields: [
        { name: 'id', label: 'ID', type: 'text' as const, path: 'id' },
        { name: 'status', label: 'Status', type: 'text' as const, path: 'status', writable: true },
      ],
    };

    it('round-trips a model; empty paths mean the operation is off', () => {
      const d = modelDraft(model);
      expect(d.operations.update).toEqual({ method: 'PATCH', path: '' });
      expect(d.subset).toEqual([{ key: 'archiviert', value: 'false' }]);
      const back = toRestModel(d);
      expect(back.operations).toEqual(model.operations);
      expect(back.subset).toEqual({ archiviert: 'false' });
      expect(back.fields[1]).toEqual({
        name: 'status',
        label: 'Status',
        type: 'text',
        path: 'status',
        writable: false,
      });
    });

    it('keeps writable only while update or create is set', () => {
      const d = modelDraft(model);
      d.operations.update = { method: 'PATCH', path: '/antraege/{id}' };
      expect(toRestModel(d).fields[1]?.writable).toBe(true);
    });

    it('validates names, {id}, paths and duplicates', () => {
      const d = modelDraft(model);
      d.operations.load = { method: 'GET', path: '/antraege' };
      d.fields.push({ name: 'status', label: '', type: 'text', path: 'a..b', writable: false });
      d.listPath = '1x';
      expect(validateModel(d)).toEqual({
        'operations.load': 'sources.model.idPlaceholder',
        listPath: 'sources.model.path',
        'fields.2': 'sources.model.fieldDuplicate',
      });
      expect(validateModel({ ...modelDraft(null), name: '' })).toMatchObject({
        name: 'sources.name.missing',
        label: 'sources.name.missing',
        connection: 'sources.model.connection',
        fields: 'sources.model.noFields',
      });
    });

    it('creates a field from a sample path with a unique name', () => {
      expect(
        fieldFromSample({ path: 'kontakt.mail', type: 'text', sample: 'a@b.ch' }, ['id']),
      ).toEqual({
        name: 'mail',
        label: 'mail',
        type: 'text',
        path: 'kontakt.mail',
        writable: false,
      });
      expect(
        fieldFromSample({ path: 'data.status', type: 'text', sample: 'x' }, ['status']).name,
      ).toBe('status_2');
      expect(fieldFromSample({ path: 'x.geprueft_am', type: 'weird', sample: null }, []).type).toBe(
        'text',
      );
    });

    it('flattens the sample tree; lists are not selectable', () => {
      expect(
        sampleNodes({ data: { id: 'A', kontakt: { mail: 'm' }, tags: [1, 2] }, ok: true }),
      ).toEqual([
        { depth: 0, key: 'data', path: 'data', kind: 'group' },
        { depth: 1, key: 'id', path: 'data.id', kind: 'leaf', value: '"A"' },
        { depth: 1, key: 'kontakt', path: 'data.kontakt', kind: 'group' },
        { depth: 2, key: 'mail', path: 'data.kontakt.mail', kind: 'leaf', value: '"m"' },
        { depth: 1, key: 'tags', path: 'data.tags', kind: 'list', value: '2' },
        { depth: 0, key: 'ok', path: 'ok', kind: 'leaf', value: 'true' },
      ]);
    });
  });

  describe('triggers', () => {
    const trigger = {
      id: 'offen',
      name: 'Offene',
      model: 'antrag',
      conditions: [
        { field: 'betrag', operator: '>', value: 1000 },
        { field: 'geprueft_am', operator: 'empty', value: null },
      ],
      definitionId: 'antrag-pruefung',
      inputs: { antrag_id: 'id' },
      intervalMinutes: 120,
      lockDays: 7,
      active: true,
    };
    const fields = [
      { name: 'betrag', type: 'number' as const },
      { name: 'archiviert', type: 'bool' as const },
      { name: 'geprueft_am', type: 'date' as const },
    ];

    it('shows whole hours as hours and converts values by field type', () => {
      const d = triggerDraft(trigger);
      expect(d).toMatchObject({ every: 2, unit: 'h' });
      expect(d.conditions[0]).toEqual({ field: 'betrag', operator: '>', value: '1000' });
      d.conditions.push({ field: 'archiviert', operator: '=', value: 'false' });
      const back = toTrigger(d, fields);
      expect(back.intervalMinutes).toBe(120);
      expect(back.conditions).toEqual([
        { field: 'betrag', operator: '>', value: 1000 },
        { field: 'geprueft_am', operator: 'empty', value: null },
        { field: 'archiviert', operator: '=', value: false },
      ]);
      expect(triggerDraft({ ...trigger, intervalMinutes: 15 })).toMatchObject({
        every: 15,
        unit: 'min',
      });
    });

    it('validates model, workflow, required inputs, interval and lock', () => {
      const d = { ...triggerDraft(null), id: 'x', every: 3, unit: 'min' as const, lockDays: 0 };
      expect(
        validateTrigger(d, [
          { name: 'antrag_id', required: true },
          { name: 'frei', required: false },
        ]),
      ).toEqual({
        model: 'sources.trigger.model',
        definitionId: 'sources.trigger.workflow',
        'inputs.antrag_id': 'sources.trigger.inputRequired',
        intervalMinutes: 'sources.trigger.interval',
        lockDays: 'sources.trigger.lock',
      });
    });
  });
});
