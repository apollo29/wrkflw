import { expect, Page, test } from '@playwright/test';

/**
 * Abnahme 9.15 (Punkte 1–14) gegen die Demo. Jeder Test trägt die Nummer des Punkts;
 * `docs/abnahme.md` ordnet jedem Punkt seine Belege zu, auch aus den anderen Suiten.
 */

const EDITOR = '/admin/workflows/antrag-pruefung';

async function open(page: Page): Promise<void> {
  await page.goto(EDITOR);
  await expect(page.getByTestId('editor-version')).toHaveText('v3');
}

const listItem = (page: Page, name: string) =>
  page.getByTestId('step-list').locator(`[data-step="${name}"]`);
const tile = (page: Page, name: string) =>
  page.getByTestId('flow-frame').locator('.wf-ed-tile', { hasText: name });

test.describe('Abnahme 9.15', () => {
  test('9.15-1 alle sechs Schritt-Arten anlegen, einstellen und löschen', async ({ page }) => {
    await open(page);
    const kinds = ['automatic', 'interactive', 'timer', 'workflow', 'data-check', 'data-write'];
    for (const [i, kind] of kinds.entries()) {
      await page.locator(`[data-kind="${kind}"]`).click();
      const name = page.getByTestId('step-name');
      await expect(name).toHaveValue(/^schritt_\d+$/);
      await name.fill(`neu_${i + 1}`);
      await name.press('Enter');
      await expect(listItem(page, `neu_${i + 1}`)).toBeVisible();
    }
    for (const i of [1, 2, 3, 4, 5, 6]) {
      await page.getByRole('button', { name: `neu_${i} löschen` }).click();
      await expect(listItem(page, `neu_${i}`)).toHaveCount(0);
    }
    await expect(page.getByTestId('step-list').locator('[data-step]')).toHaveCount(8);
  });

  test('9.15-2 Beispielablauf 9.13: Verzweigung, Zusammenlauf, Folge in einer Zeile', async ({
    page,
  }) => {
    await open(page);
    const lanes = page.getByTestId('flow-lane');
    await expect(lanes.first()).toContainText("context['antrag_betrag'] > 1000");
    await expect(lanes.first()).toContainText('pruefen');
    await expect(lanes.nth(1)).toContainText('Sonst');
    await expect(lanes.nth(1)).toContainText('warten');
    await expect(page.getByText('Zusammenführung')).toBeVisible();
    const top = async (name: string) => (await tile(page, name).first().boundingBox())!.y;
    expect(await top('bestaetigung')).toBe(await top('abschluss'));
    expect(await top('speichern')).toBe(await top('bestaetigung'));
  });

  test('9.15-3 Klick auf ein Plättchen wählt denselben Schritt wie die Schrittliste', async ({
    page,
  }) => {
    await open(page);
    await tile(page, 'speichern').first().click();
    await expect(page.getByTestId('step-name')).toHaveValue('speichern');
    await expect(listItem(page, 'speichern')).toHaveAttribute('aria-selected', 'true');
    await expect(tile(page, 'speichern').first()).toHaveAttribute('aria-pressed', 'true');
    await listItem(page, 'warten').click();
    await expect(tile(page, 'warten').first()).toHaveAttribute('aria-pressed', 'true');
  });

  test('9.15-4 jede Eingabe ändert Vorschau und Kontext-Variablen sofort', async ({ page }) => {
    await open(page);
    await page.getByLabel('Ergebnis-Variable').fill('kunde');
    await expect(page.getByTestId('context-vars')).toContainText('{{kunde_betrag}}');
    await expect(page.getByTestId('context-vars')).not.toContainText('{{antrag_mail}}');
    await listItem(page, 'warten').click();
    await page.getByTestId('step-name').fill('pause');
    await page.getByTestId('step-name').press('Enter');
    await expect(page.getByTestId('flow-lane').nth(1)).toContainText('pause');
  });

  test('9.15-5 Bedingung mit dem Assistenten, Ausdruck sichtbar', async ({ page }) => {
    await open(page);
    await listItem(page, 'speichern').click();
    await page.getByTestId('add-transition').click();
    await page
      .getByTestId('transition-else')
      .last()
      .getByRole('button', { name: 'Bedingung…' })
      .click();
    const card = page.getByTestId('transition-card');
    await card.getByTestId('condition-field').fill('antrag_betrag');
    await card.getByTestId('condition-value').fill('500');
    await expect(card.getByTestId('condition-expression')).toContainText(
      "context['antrag_betrag']",
    );
    await expect(card.getByTestId('condition-expression')).toContainText('500');
  });

  test('9.15-6 «Visuell» ↔ «JSON» verliert nichts', async ({ page }) => {
    await open(page);
    const json = page.getByTestId('editor-json');
    // 9.14: «Visuell» in der visuellen Ansicht tut nichts.
    await page.getByRole('radio', { name: 'Visuell' }).click();
    await expect(page.getByTestId('editor-dirty')).toHaveCount(0);

    await page.getByRole('radio', { name: 'JSON' }).click();
    const first = await json.inputValue();
    await page.getByRole('radio', { name: 'Visuell' }).click();
    await page.getByRole('radio', { name: 'JSON' }).click();
    expect(await json.inputValue()).toBe(first);

    await json.fill(first.replace('"name": "Antrag prüfen"', '"name": "Antrag geprüft"'));
    await page.getByRole('radio', { name: 'Visuell' }).click();
    await expect(page.getByTestId('editor-name')).toHaveValue('Antrag geprüft');
    await expect(page.getByTestId('step-list').locator('[data-step]')).toHaveCount(8);
  });

  test('9.15-7 Speichern zeigt Erfolg oder Fehler, die Versionspille stimmt', async ({ page }) => {
    await open(page);
    await page.locator('[data-kind="automatic"]').click();
    await expect(page.getByTestId('editor-issues')).toBeVisible();
    await expect(page.getByTestId('editor-save')).toBeDisabled();
    await page.getByRole('button', { name: 'schritt_1 löschen' }).click();
    await page.getByTestId('editor-name').fill('Antrag prüfen 2');
    await page.getByTestId('editor-save').click();
    await expect(page.getByTestId('editor-message')).toHaveText(
      'Gespeichert: antrag-pruefung v4 (aktiv).',
    );
    await expect(page.getByTestId('editor-version')).toHaveText('v4');
  });

  test('9.15-8 Übersicht: alle Workflows über die ganze Breite, Klick öffnet den Editor', async ({
    page,
  }) => {
    await page.goto('/admin');
    const rows = page.locator('[data-workflow]');
    await expect(rows).toHaveCount(3);
    const row = page.locator('[data-workflow="antrag-pruefung"]');
    await expect(row).toContainText('v3');
    await expect(row.locator('svg[role="img"]')).toBeVisible();
    const width = await page.evaluate(
      () =>
        document.querySelector('wf-admin-shell main, wf-admin-shell')!.getBoundingClientRect()
          .width,
    );
    expect((await row.boundingBox())!.width).toBeGreaterThan(width * 0.9);
    await row.getByRole('link').first().click();
    await expect(page).toHaveURL(/\/admin\/workflows\/antrag-pruefung$/);
  });

  test('9.15-9 ältere Versionen: entfernbar oder mit Grund gesperrt', async ({ page }) => {
    await page.goto('/admin');
    await page.locator('[data-workflow="antrag-pruefung"]').getByTestId('overview-older').click();
    const v1 = page.locator('[data-version="1"]');
    await expect(
      v1.getByRole('button', { name: 'Version v1 entfernen – gesperrt' }),
    ).toBeDisabled();
    await expect(v1).toContainText('Ein abgeschlossener Durchlauf verweist noch darauf.');
    await expect(
      page.locator('[data-version="2"]').getByRole('button', { name: 'Version v2 entfernen' }),
    ).toBeEnabled();
  });

  test('9.15-10 nur Bearbeitungs-Spalten; Verlassen fragt nach', async ({ page }) => {
    await open(page);
    await expect(page.locator('wf-editor-side')).toBeVisible();
    await expect(page.locator('wf-step-detail')).toBeVisible();
    await page.getByTestId('editor-name').fill('Geändert');
    await page.getByRole('link', { name: 'Vorlagen' }).click();
    await expect(page.getByRole('dialog', { name: 'Ungespeicherte Änderungen' })).toBeVisible();
  });

  test('9.15-11 Vorlage mit Tabelle, Vorschau und Verwendung', async ({ page }) => {
    await page.goto('/admin/vorlagen?id=antrag-ok');
    await expect(page.getByTestId('templates-usage')).toContainText('Verwendet in');
    await page.locator('.wf-html__content table').first().click();
    await expect(page.getByTestId('html-tablebar')).toBeVisible();
    await page.getByTestId('html-preview-toggle').click();
    await expect(
      page.frameLocator('[data-testid="html-preview-frame"]').locator('table'),
    ).toBeVisible();
  });

  test('9.15-12 hell und dunkel lesbar, 375 px ohne waagrechtes Scrollen', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 800 });
    for (const scheme of ['light', 'dark'] as const) {
      await page.emulateMedia({ colorScheme: scheme });
      for (const url of [
        '/admin',
        EDITOR,
        '/admin/instanzen',
        '/admin/vorlagen?id=antrag-ok',
        '/admin/datenquellen?bereich=modelle&id=antrag',
      ]) {
        await page.goto(url);
        await expect(page.locator('wf-admin-shell')).toHaveAttribute(
          'data-theme',
          scheme === 'dark' ? 'dunkel' : 'hell',
        );
        await page.waitForLoadState('networkidle');
        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - window.innerWidth,
        );
        expect(overflow, `${scheme} ${url}`).toBeLessThanOrEqual(0);
      }
    }
  });

  test('9.15-13 Spaltenbreiten folgen dem 12er-Raster in drei Stufen', async ({ page }) => {
    for (const [width, side] of [
      [1440, 3],
      [1000, 4],
      [375, 12],
    ] as const) {
      await page.setViewportSize({ width, height: 900 });
      await open(page);
      const s = (await page.locator('wf-editor-side').boundingBox())!;
      const d = (await page.locator('wf-step-detail').boundingBox())!;
      if (side === 12) {
        expect(Math.abs(s.width - d.width)).toBeLessThan(2);
      } else {
        // Seitenspalte : Detail = side : (12 - side), Abstand des Rasters herausgerechnet.
        const ratio = (s.width + 16) / (d.width + 16);
        expect(ratio).toBeCloseTo(side / (12 - side), 1);
      }
      if (width === 1440) {
        // Sechs Typ-Karten, je 2 von 12 Spalten, in einer Reihe (9.14).
        const cards = page.locator('[data-kind]');
        await expect(cards).toHaveCount(6);
        const tops = new Set<number>();
        for (const box of await cards.evaluateAll((els) =>
          els.map((e) => Math.round(e.getBoundingClientRect().top)),
        )) {
          tops.add(box);
        }
        expect(tops.size).toBe(1);
      }
    }
  });

  test('9.15-14 Punkte aus 9.14 im Browser', async ({ page }) => {
    // Archiv: die gewählte Version öffnet sich schreibgeschützt.
    await page.goto('/admin/workflows/antrag-pruefung/v/1');
    await expect(page.getByTestId('editor-version')).toHaveText('v1');
    await expect(page.getByText('Ältere Version – schreibgeschützt.')).toBeVisible();

    // Knopfbeschriftung statt «SONST» in der Spur.
    await page.goto('/admin/workflows/neu');
    await page.getByRole('radio', { name: 'JSON' }).click();
    await page.getByTestId('editor-json').fill(
      JSON.stringify({
        id: 'knoepfe',
        name: 'Knöpfe',
        startStep: 'frage',
        steps: {
          frage: {
            type: 'interactive',
            ui: { title: 'Frage' },
            transitions: [
              { to: 'ja', event: 'ja', label: 'Ja' },
              { to: 'nein', event: 'nein', label: 'Nein' },
            ],
          },
          ja: { type: 'timer', delaySeconds: 60, transitions: [] },
          nein: { type: 'timer', delaySeconds: 60, transitions: [] },
        },
      }),
    );
    await page.getByRole('radio', { name: 'Visuell' }).click();
    await expect(page.getByTestId('flow-lane').first()).toContainText('Knopf «Ja»');
    await expect(page.getByTestId('flow-lane').nth(1)).toContainText('Knopf «Nein»');
    await expect(page.getByTestId('flow-frame')).not.toContainText('Sonst');

    await page.goto(EDITOR);
    page.once('dialog', (d) => void d.accept());
    const leave = page.getByRole('dialog', { name: 'Ungespeicherte Änderungen' });
    if (await leave.isVisible().catch(() => false)) {
      await leave.getByRole('button', { name: 'Verwerfen' }).click();
    }
    await expect(page.getByTestId('editor-version')).toHaveText('v3');
    // Doppelte Namen verhindert, Fehler am Schritt.
    await listItem(page, 'warten').click();
    await page.getByTestId('step-name').fill('pruefen');
    await page.getByTestId('step-name').press('Enter');
    await expect(page.getByText('Diesen Namen hat schon ein anderer Schritt.')).toBeVisible();
    await expect(page.getByTestId('step-list').locator('[data-step="pruefen"]')).toHaveCount(1);
    // Lesbare Aktionsnamen, Aktionen mit eigener Karte nicht doppelt.
    await page.getByTestId('step-name').fill('warten');
    await listItem(page, 'abgelehnt').click();
    const options = await page.getByTestId('step-action').locator('option').allTextContents();
    expect(options.join('|')).toContain('E-Mail senden');
    expect(options.join('|')).not.toContain('send_email');
    expect(options.join('|')).not.toContain('Daten prüfen');

    // Abgebrochene Durchläufe zählen als beendet.
    await page.goto('/admin');
    await expect(page.locator('[data-workflow="antrag-pruefung"]')).toContainText('2 laufend');
    // Reiter per Tastatur.
    await page.getByRole('link', { name: 'Instanzen' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/admin\/instanzen/);
  });
});
