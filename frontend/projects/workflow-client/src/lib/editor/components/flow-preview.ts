import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { WfI18n, WfTranslatePipe } from '../../i18n/i18n';
import { WfButton } from '../../ui/button';
import { WfIcon } from '../../ui/icon';
import { stepKindInfo } from '../../ui/step-kinds';
import { forkPaths, laneGeometry, mergePaths } from '../model/flow-geometry';
import { stepKind } from '../model/operations';
import { EditorStore } from '../state/editor-store';

const COLLAPSE_KEY = 'wf.editor.flowCollapsed';

function readCollapsed(): boolean {
  try {
    return globalThis.localStorage?.getItem(COLLAPSE_KEY) === '1';
  } catch {
    return false;
  }
}

/**
 * Ablauf-Vorschau (9.5): Kacheln in Reihen, Verzweigungen als eigenes Bild mit Spuren,
 * Zusammenführung und Beschriftung. Breite Bilder scrollen in ihrem Rahmen (E-18).
 */
@Component({
  selector: 'wf-flow-preview',
  imports: [NgTemplateOutlet, WfButton, WfIcon, WfTranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { class: 'wf-zone wf-ed-section', role: 'region', '[attr.aria-label]': 'regionLabel' },
  template: `
    <div class="wf-ed-section__head">
      <h3>{{ 'editor.flow.title' | wfT }}</h3>
      <button
        type="button"
        wfButton
        variant="text"
        size="sm"
        [attr.aria-expanded]="!collapsed()"
        (click)="toggle()"
      >
        {{ (collapsed() ? 'editor.flow.expand' : 'editor.flow.collapse') | wfT }}
      </button>
    </div>
    @if (!collapsed()) {
      <div class="wf-ed-flow__frame" data-testid="flow-frame">
        @if (store.steps().length === 0) {
          <span class="wf-muted">{{ 'editor.flow.empty' | wfT }}</span>
        } @else {
          <div class="wf-ed-flow__rows">
            @for (section of store.sections(); track $index; let first = $first) {
              @switch (section.kind) {
                @case ('chain') {
                  <span class="wf-ed-flow__group">
                    @if (!first) {
                      <ng-container *ngTemplateOutlet="arrow" />
                    }
                    @for (name of section.steps; track name; let i = $index) {
                      @if (i > 0) {
                        <ng-container *ngTemplateOutlet="arrow" />
                      }
                      <ng-container *ngTemplateOutlet="tile; context: { $implicit: name }" />
                    }
                  </span>
                }
                @case ('fork') {
                  <span class="wf-ed-flow__fork" data-testid="flow-fork">
                    @if (section.prev) {
                      <ng-container
                        *ngTemplateOutlet="tile; context: { $implicit: section.prev }"
                      />
                      <ng-container *ngTemplateOutlet="arrow" />
                    }
                    <ng-container *ngTemplateOutlet="tile; context: { $implicit: section.from }" />
                    @let geo = geometry(section.lanes.length);
                    <svg
                      class="wf-ed-flow__svg"
                      [attr.height]="geo.height"
                      [attr.viewBox]="'0 0 56 ' + geo.height"
                      aria-hidden="true"
                    >
                      @for (d of forks(section.lanes.length); track $index) {
                        <path [attr.d]="d" />
                      }
                    </svg>
                    <span class="wf-ed-flow__lanes">
                      @for (lane of section.lanes; track $index) {
                        <span class="wf-ed-flow__lane" data-testid="flow-lane">
                          @switch (lane.label.kind) {
                            @case ('condition') {
                              <span class="wf-ed-flow__cond">{{ lane.label.text }}</span>
                            }
                            @case ('button') {
                              <span class="wf-ed-flow__cond">{{
                                'editor.flow.button' | wfT: { label: lane.label.text }
                              }}</span>
                            }
                            @default {
                              <span class="wf-ed-flow__else">{{ 'editor.flow.else' | wfT }}</span>
                            }
                          }
                          @for (name of lane.steps; track name) {
                            <ng-container *ngTemplateOutlet="arrow" />
                            <ng-container *ngTemplateOutlet="tile; context: { $implicit: name }" />
                          }
                          @if (section.merge) {
                            <span class="wf-ed-flow__lane-fill" aria-hidden="true"></span>
                          }
                        </span>
                      }
                    </span>
                    @if (section.merge; as merge) {
                      <span class="wf-ed-flow__merge">
                        <span class="wf-ed-flow__merge-label">{{ 'editor.flow.merge' | wfT }}</span>
                        <svg
                          class="wf-ed-flow__svg"
                          [attr.height]="geo.height"
                          [attr.viewBox]="'0 0 56 ' + geo.height"
                          aria-hidden="true"
                        >
                          @for (d of merges(section.lanes.length); track $index) {
                            <path [attr.d]="d" />
                          }
                        </svg>
                      </span>
                      <ng-container *ngTemplateOutlet="tile; context: { $implicit: merge }" />
                      @for (name of section.tail; track name) {
                        <ng-container *ngTemplateOutlet="arrow" />
                        <ng-container *ngTemplateOutlet="tile; context: { $implicit: name }" />
                      }
                      @if (section.continues) {
                        <span class="wf-ed-flow__more" aria-hidden="true">…</span>
                      }
                    }
                  </span>
                }
                @case ('unreachable') {
                  <span class="wf-ed-flow__unreachable">
                    <span class="wf-lbl">{{ 'editor.flow.unreachable' | wfT }}</span>
                    @for (name of section.steps; track name) {
                      <ng-container *ngTemplateOutlet="tile; context: { $implicit: name }" />
                    }
                  </span>
                }
              }
            }
          </div>
        }
      </div>
    }

    <ng-template #arrow>
      <svg class="wf-ed-flow__arrow" viewBox="0 0 22 10" aria-hidden="true">
        <path d="M1 5h18M15 1.5 19 5l-4 3.5" />
      </svg>
    </ng-template>
    <ng-template #tile let-name>
      @let info = kindOf(name);
      <button
        type="button"
        class="wf-ed-tile"
        [class]="info.colorClass"
        [class.wf-ed-tile--invalid]="store.stepsWithErrors().has(name)"
        [attr.aria-pressed]="store.selected() === name"
        (click)="store.select(name)"
      >
        <wf-icon [name]="info.icon" />{{ name }}
      </button>
    </ng-template>
  `,
})
export class WfFlowPreview {
  protected readonly store = inject(EditorStore);
  protected readonly collapsed = signal(readCollapsed());
  protected readonly regionLabel = inject(WfI18n).t('editor.flow.label');
  private readonly byName = computed(
    () => new Map(this.store.steps().map((s) => [s.name, stepKindInfo(stepKind(s))])),
  );

  protected kindOf(name: string): ReturnType<typeof stepKindInfo> {
    return this.byName().get(name) ?? stepKindInfo('automatic');
  }

  protected geometry(lanes: number): ReturnType<typeof laneGeometry> {
    return laneGeometry(lanes);
  }

  protected forks(lanes: number): string[] {
    return forkPaths(lanes);
  }

  protected merges(lanes: number): string[] {
    return mergePaths(lanes);
  }

  protected toggle(): void {
    const next = !this.collapsed();
    this.collapsed.set(next);
    try {
      globalThis.localStorage?.setItem(COLLAPSE_KEY, next ? '1' : '0');
    } catch {
      // Ohne Speicher gilt die Wahl nur bis zum Neuladen (E-19).
    }
  }
}
