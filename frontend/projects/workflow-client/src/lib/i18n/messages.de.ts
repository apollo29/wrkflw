/**
 * Deutscher Nachrichtenkatalog. Jeder sichtbare Text der Library steht hier; Templates
 * verwenden nur Schlüssel. Platzhalter in geschweiften Klammern: `{n}`, `{id}` …
 *
 * Die Host-App ersetzt Texte über `provideWorkflowAdmin({ messages: { … } })`.
 */
export const MESSAGES_DE = {
  // Navigation und Shell
  'app.title': 'Workflows',
  'nav.label': 'Bereiche',
  'nav.workflows': 'Workflows',
  'nav.instances': 'Instanzen',
  'nav.templates': 'Vorlagen',
  'nav.dataSources': 'Datenquellen',
  'nav.backToWorkflows': '← Alle Workflows',
  'page.placeholder': 'Dieser Bereich folgt in Phase {phase}.',
  'page.editor.title': 'Editor: {id}',
  'page.editor.version': 'Version {version} (schreibgeschützt)',

  // Allgemeine Aktionen
  'action.save': 'Speichern',
  'action.cancel': 'Abbrechen',
  'action.close': 'Schliessen',
  'action.delete': 'Löschen',
  'action.undo': 'Rückgängig',
  'action.redo': 'Wiederholen',
  'action.retry': 'Erneut versuchen',
  'action.refresh': 'Aktualisieren',
  'action.add': 'Hinzufügen',
  'action.open': 'Öffnen',

  // Ungespeicherte Änderungen (E-08)
  'dialog.unsaved.title': 'Ungespeicherte Änderungen',
  'dialog.unsaved.text':
    'Es gibt Änderungen, die noch nicht gespeichert sind. Was soll damit geschehen?',
  'dialog.unsaved.discard': 'Verwerfen',
  'dialog.unsaved.stay': 'Im Editor bleiben',
  'dialog.unsaved.saveAndLeave': 'Speichern und verlassen',
  'dialog.unsaved.saveFailed': 'Speichern hat nicht geklappt. Die Änderungen sind noch da.',
  'state.unsaved': 'Ungespeicherte Änderungen',

  // Status eines Durchlaufs (aus 2.x workflow.status.ts)
  'status.instance.running': 'Läuft',
  'status.instance.waiting_event': 'Wartet auf eine Rückmeldung',
  'status.instance.waiting_timer': 'Wartet auf den nächsten Termin',
  'status.instance.completed': 'Abgeschlossen',
  'status.instance.failed': 'Fehlgeschlagen',
  'status.instance.cancelled': 'Abgebrochen',
  'status.instance.waitingText.waiting_event':
    'Wartet auf eine Rückmeldung von aussen – hier ist gerade nichts zu tun.',
  'status.instance.waitingText.waiting_timer':
    'Wartet auf den nächsten Termin – es geht automatisch weiter.',
  'status.instance.finishedText.completed': 'Der Ablauf ist abgeschlossen.',
  'status.instance.finishedText.failed': 'Der Ablauf ist abgebrochen.',
  'status.instance.finishedText.cancelled': 'Der Ablauf wurde abgebrochen.',

  // Status einer Definition
  'status.definition.active': 'Aktiv',
  'status.definition.draft': 'Entwurf',
  'status.definition.inactive': 'Inaktiv',

  // Schritt-Arten
  'step.kind.automatic': 'Automatisch',
  'step.kind.interactive': 'Interaktiv',
  'step.kind.timer': 'Timer',
  'step.kind.workflow': 'Workflow',
  'step.kind.data-check': 'Datencheck',
  'step.kind.data-write': 'Daten schreiben',
  'step.kind.automatic.description': 'Läuft im Hintergrund und führt eine Aktion aus.',
  'step.kind.interactive.description': 'Hält an und zeigt dem Benutzer eine Seite.',
  'step.kind.timer.description': 'Wartet eine festgelegte Zeit.',
  'step.kind.workflow.description': 'Stösst einen anderen Workflow an.',
  'step.kind.data-check.description': 'Liest einen Wert aus einer Tabelle.',
  'step.kind.data-write.description': 'Ändert Werte in einer Tabelle.',

  // Meldungen
  'toast.region': 'Meldungen',
  'theme.light': 'Hell',
  'theme.dark': 'Dunkel',
  'theme.label': 'Darstellung',

  // Fehler der API
  'error.network': 'Der Server ist nicht erreichbar. Bitte die Verbindung prüfen.',
  'error.unauthorized': 'Bitte anmelden.',
  'error.forbidden': 'Dafür fehlt das Recht.',
  'error.not_found': 'Nicht gefunden.',
  'error.internal_error': 'Auf dem Server ist ein Fehler aufgetreten.',
  'error.unknown': 'Etwas hat nicht geklappt.',
  'error.fieldsTitle': 'Bitte die markierten Angaben prüfen.',
} as const;

export type WfMessageKey = keyof typeof MESSAGES_DE;

/** Ein vollständiger Katalog: dieselben Schlüssel, beliebige Texte. */
export type WfMessages = Record<WfMessageKey, string>;
