import { DestroyRef, inject } from '@angular/core';
import { CanDeactivateFn } from '@angular/router';
import { WfI18n } from '../i18n/i18n';
import { WfDialogService } from '../ui/dialog';
import { WfToastService } from '../ui/toast';

/** Eine Seite mit Änderungen, die beim Verlassen verloren gingen (E-08). */
export interface WfUnsavedChanges {
  hasUnsavedChanges(): boolean;
  /** Speichert; `false`, wenn es nicht geklappt hat (die Seite bleibt dann offen). */
  save?(): Promise<boolean>;
}

type Choice = 'discard' | 'stay' | 'save';

/**
 * Fragt vor dem Verlassen nach: Verwerfen, Im Editor bleiben oder Speichern und verlassen.
 * Für Routen: `canDeactivate: [wfUnsavedChangesGuard]`.
 */
export const wfUnsavedChangesGuard: CanDeactivateFn<WfUnsavedChanges> = async (component) => {
  if (!component?.hasUnsavedChanges()) {
    return true;
  }
  const i18n = inject(WfI18n);
  const dialogs = inject(WfDialogService);
  const toasts = inject(WfToastService);
  const save = component.save?.bind(component);

  const choice = await dialogs.confirm<Choice>({
    title: i18n.t('dialog.unsaved.title'),
    text: i18n.t('dialog.unsaved.text'),
    cancelValue: 'stay',
    actions: [
      { value: 'discard', label: i18n.t('dialog.unsaved.discard'), variant: 'danger' },
      { value: 'stay', label: i18n.t('dialog.unsaved.stay') },
      ...(save
        ? [
            {
              value: 'save' as const,
              label: i18n.t('dialog.unsaved.saveAndLeave'),
              variant: 'primary' as const,
            },
          ]
        : []),
    ],
  });

  switch (choice) {
    case 'discard':
      return true;
    case 'stay':
      return false;
    case 'save': {
      const ok = save ? await save().catch(() => false) : false;
      if (!ok) {
        toasts.show({ message: i18n.t('dialog.unsaved.saveFailed') });
      }
      return ok;
    }
  }
};

/**
 * Warnt beim Schliessen oder Neuladen des Tabs, solange Änderungen offen sind. Aufruf im
 * Konstruktor der Seite: `wfBeforeUnload(() => this.dirty())`.
 */
export function wfBeforeUnload(hasUnsavedChanges: () => boolean): void {
  if (typeof window === 'undefined') {
    return;
  }
  const listener = (event: BeforeUnloadEvent): void => {
    if (hasUnsavedChanges()) {
      event.preventDefault();
      // Ältere Browser brauchen einen gesetzten Rückgabewert.
      event.returnValue = '';
    }
  };
  window.addEventListener('beforeunload', listener);
  inject(DestroyRef).onDestroy(() => window.removeEventListener('beforeunload', listener));
}
