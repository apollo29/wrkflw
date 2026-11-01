import { Component, computed, inject, signal } from '@angular/core';
import {
  definitionStatusPill,
  instanceStatusPill,
  InstanceStatus,
  STEP_KINDS,
  stepKindInfo,
  WfBanner,
  WfButton,
  WfDialogService,
  WfField,
  WfIcon,
  WfPill,
  WfSegment,
  WfSegmentOption,
  WfThemeService,
  WfToastHost,
  WfToastService,
  WfTranslatePipe,
} from '@apollo29/workflow-client';

/** Alle Bausteine auf einer Seite, zum Abgleich mit dem Design-Canvas (Demo, nicht Library). */
@Component({
  selector: 'demo-gallery',
  imports: [WfBanner, WfButton, WfField, WfIcon, WfPill, WfSegment, WfToastHost, WfTranslatePipe],
  host: { class: 'wf', '[attr.data-theme]': 'theme.theme()' },
  templateUrl: './gallery.html',
  styleUrl: './gallery.css',
})
export class Gallery {
  protected readonly theme = inject(WfThemeService);
  private readonly dialogs = inject(WfDialogService);
  private readonly toasts = inject(WfToastService);

  protected readonly kinds = STEP_KINDS.map(stepKindInfo);
  protected readonly instanceStates: InstanceStatus[] = [
    'running',
    'waiting_event',
    'waiting_timer',
    'completed',
    'failed',
    'cancelled',
  ];
  protected readonly instancePill = instanceStatusPill;
  protected readonly definitionPills = (['active', 'draft', 'inactive'] as const).map(
    definitionStatusPill,
  );

  protected readonly themeOptions: WfSegmentOption<'hell' | 'dunkel'>[] = [
    { value: 'hell', label: 'Hell' },
    { value: 'dunkel', label: 'Dunkel' },
  ];
  protected readonly themeValue = computed(() => this.theme.theme());

  protected readonly status = signal<'active' | 'draft' | 'inactive'>('active');
  protected readonly statusOptions: WfSegmentOption<'active' | 'draft' | 'inactive'>[] = [
    { value: 'active', label: 'Aktiv', ok: true },
    { value: 'draft', label: 'Entwurf' },
    { value: 'inactive', label: 'Inaktiv' },
  ];

  protected readonly name = signal('');
  protected readonly nameError = computed(() =>
    this.name().trim() === '' ? 'Bitte einen Namen angeben.' : null,
  );
  protected readonly dialogResult = signal<string | null>(null);

  protected setTheme(value: 'hell' | 'dunkel'): void {
    this.theme.set(value);
  }

  protected async openDialog(): Promise<void> {
    const result = await this.dialogs.confirm({
      title: 'Ungespeicherte Änderungen',
      text: 'Es gibt Änderungen, die noch nicht gespeichert sind.',
      cancelValue: 'stay',
      actions: [
        { value: 'discard', label: 'Verwerfen', variant: 'danger' },
        { value: 'stay', label: 'Im Editor bleiben' },
        { value: 'save', label: 'Speichern und verlassen', variant: 'primary' },
      ],
    });
    this.dialogResult.set(result);
  }

  protected showToast(): void {
    this.toasts.show({
      message: 'Schritt «Prüfen» gelöscht.',
      actionLabel: 'Rückgängig',
      onAction: () => this.toasts.show({ message: 'Wiederhergestellt.' }),
    });
  }
}
