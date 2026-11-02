/** Beispieldaten der Demo nach Abschnitt 9.13 (Antrag prüfen). */
export const DEMO_DEFINITIONS: Record<string, unknown>[] = [
  {
    id: 'antrag-pruefung',
    name: 'Antrag prüfen',
    startStep: 'daten_laden',
    inputs: [{ name: 'antrag_id', label: 'Antrag', required: true, beispiel: 'A-1024' }],
    steps: {
      daten_laden: {
        type: 'automatic',
        action: 'check_data',
        config: {
          entity: 'antrag',
          id: '{{antrag_id}}',
          field: 'status',
          fields: ['betrag', 'mail'],
          as: 'antrag',
        },
        transitions: [{ to: 'pruefen', when: "context['antrag_betrag'] > 1000" }, { to: 'warten' }],
      },
      pruefen: {
        type: 'interactive',
        ui: {
          title: 'Antrag prüfen',
          description: 'Bitte den Antrag prüfen.',
          fields: [{ name: 'bemerkung', label: 'Bemerkung', type: 'text' }],
        },
        transitions: [{ to: 'speichern', event: 'submit', label: 'Freigeben' }],
      },
      warten: { type: 'timer', delaySeconds: 3600, transitions: [{ to: 'speichern' }] },
      speichern: {
        type: 'automatic',
        action: 'write_data',
        config: {
          entity: 'antrag',
          id: '{{antrag_id}}',
          values: { status: 'freigegeben', geprueft_am: '{{now}}' },
          as: 'written',
        },
        transitions: [{ to: 'bestaetigung' }],
      },
      bestaetigung: {
        type: 'automatic',
        action: 'send_email',
        config: {
          to: '{{antrag_mail}}',
          subject: 'Ihr Antrag {{antrag_id}}',
          body: '<p>Ihr Antrag wurde geprüft.</p>',
        },
        transitions: [{ to: 'abschluss' }],
      },
      abschluss: {
        type: 'automatic',
        action: 'start_workflow',
        config: { workflowId: 'archivierung' },
        transitions: [],
      },
    },
  },
  {
    id: 'mahnung',
    name: 'Mahnung',
    draft: true,
    startStep: 'erinnern',
    steps: {
      erinnern: { type: 'timer', delaySeconds: 604800, transitions: [{ to: 'mail' }] },
      mail: {
        type: 'automatic',
        action: 'send_email',
        config: { to: '{{mail}}', subject: 'Erinnerung' },
        transitions: [],
      },
    },
  },
  {
    id: 'archivierung',
    name: 'Archivierung',
    startStep: 'ablegen',
    steps: {
      ablegen: {
        type: 'automatic',
        action: 'send_email',
        config: { to: 'archiv@example.org', subject: 'Archiv' },
        transitions: [],
      },
    },
  },
];

export const DEMO_ACTIONS = [
  {
    key: 'send_email',
    label: 'E-Mail senden',
    fields: [
      { name: 'templateId', label: 'Vorlage (optional)', type: 'template-ref' },
      { name: 'to', label: 'An', type: 'text' },
      { name: 'from', label: 'Absender (leer = Standard)', type: 'text' },
      { name: 'cc', label: 'CC (mit Komma getrennt)', type: 'text' },
      { name: 'bcc', label: 'BCC (mit Komma getrennt)', type: 'text' },
      { name: 'subject', label: 'Betreff', type: 'text' },
      { name: 'body', label: 'Inhalt (HTML)', type: 'html' },
    ],
  },
  {
    key: 'start_workflow',
    label: 'Workflow starten',
    fields: [
      { name: 'workflowId', label: 'Workflow', type: 'workflow-ref' },
      { name: 'waitForCompletion', label: 'Auf Abschluss warten', type: 'boolean' },
    ],
  },
  { key: 'check_data', label: 'Daten prüfen', fields: [] },
  { key: 'write_data', label: 'Daten schreiben', fields: [] },
  {
    key: 'notify_slack',
    label: 'Slack benachrichtigen',
    fields: [{ name: 'channel', label: 'Kanal', type: 'text' }],
  },
];

export const DEMO_MODELS = [
  {
    name: 'antrag',
    label: 'Anträge',
    idField: 'id',
    fields: [
      { name: 'status', label: 'Status', type: 'text', writable: true },
      { name: 'betrag', label: 'Betrag', type: 'number', writable: false },
      { name: 'mail', label: 'E-Mail', type: 'text', writable: false },
      { name: 'geprueft_am', label: 'Geprüft am', type: 'date', writable: true },
    ],
    operations: { load: true, find: true, update: true, create: false },
  },
];

export const DEMO_UPLOAD_HANDLERS = [
  { key: 'pdf_max_5mb', label: 'PDF bis 5 MB', description: 'Nur PDF-Dateien bis 5 MB.' },
];

export const DEMO_TEMPLATES = [
  {
    id: 'antrag-info',
    name: 'Hinweise zum Antrag',
    type: 'page',
    subject: '',
    body: '<p>Hinweise</p>',
  },
  {
    id: 'eingang',
    name: 'Eingangsbestätigung',
    type: 'email',
    subject: 'Eingang',
    body: '<p>Danke</p>',
  },
];

export interface DemoInstanceSeed {
  readonly id: string;
  readonly version: number;
  readonly status:
    'running' | 'waiting_event' | 'waiting_timer' | 'completed' | 'failed' | 'cancelled';
  readonly step: string;
  readonly subject: string;
  readonly daysAgo: number;
  readonly lastError?: string;
  readonly wakeInHours?: number;
}

/** Durchläufe nach 9.13: v3 mit 8, v1 mit 4 abgeschlossenen; 2 laufen. */
export const DEMO_INSTANCES: DemoInstanceSeed[] = [
  {
    id: 'i-1024',
    version: 3,
    status: 'waiting_event',
    step: 'pruefen',
    subject: 'A-1024',
    daysAgo: 0,
  },
  {
    id: 'i-1023',
    version: 3,
    status: 'waiting_timer',
    step: 'warten',
    subject: 'A-1023',
    daysAgo: 0,
    wakeInHours: 20,
  },
  {
    id: 'i-1022',
    version: 3,
    status: 'failed',
    step: 'bestaetigung',
    subject: 'A-1022',
    daysAgo: 1,
    lastError: 'Mailversand: Zeitüberschreitung nach 3 Versuchen.',
  },
  { id: 'i-1021', version: 3, status: 'cancelled', step: 'pruefen', subject: 'A-1021', daysAgo: 2 },
  {
    id: 'i-1020',
    version: 3,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-1020',
    daysAgo: 3,
  },
  {
    id: 'i-1019',
    version: 3,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-1019',
    daysAgo: 4,
  },
  {
    id: 'i-1018',
    version: 3,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-1018',
    daysAgo: 5,
  },
  {
    id: 'i-1017',
    version: 3,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-1017',
    daysAgo: 6,
  },
  {
    id: 'i-0904',
    version: 1,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-0904',
    daysAgo: 40,
  },
  {
    id: 'i-0903',
    version: 1,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-0903',
    daysAgo: 41,
  },
  {
    id: 'i-0902',
    version: 1,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-0902',
    daysAgo: 42,
  },
  {
    id: 'i-0901',
    version: 1,
    status: 'completed',
    step: 'abschluss',
    subject: 'A-0901',
    daysAgo: 43,
  },
];
