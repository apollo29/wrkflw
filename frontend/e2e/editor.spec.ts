import { expect, Page, test } from '@playwright/test';

const EDITOR = '/admin/workflows/antrag-pruefung';

function step(page: Page, name: string) {
  return page.getByTestId('step-list').locator(`[data-step="${name}"]`);
}

async function open(page: Page): Promise<void> {
  await page.goto(EDITOR);
  await expect(page.getByTestId('editor-version')).toHaveText('v3');
}

test.describe('Editor', () => {
  test('öffnet mit dem ersten Schritt und zeigt die Verzweigung', async ({ page }) => {
    await open(page);
    await expect(page.getByTestId('step-name')).toHaveValue('daten_laden');
    await expect(page.getByTestId('flow-lane')).toHaveCount(2);
    await expect(page.getByTestId('flow-lane').first()).toContainText(
      "context['antrag_betrag'] > 1000",
    );
    await expect(page.getByTestId('flow-lane').nth(1)).toContainText('Sonst');
    await expect(page.getByText('Zusammenführung')).toBeVisible();
  });

  test('Schritt per Karte hinzufügen, umbenennen zieht den Übergang mit', async ({ page }) => {
    await open(page);
    await page.locator('[data-kind="timer"]').click();
    await expect(page.getByTestId('step-name')).toHaveValue('schritt_1');
    await expect(step(page, 'schritt_1')).toBeVisible();
    await expect(page.getByTestId('editor-dirty')).toBeVisible();

    await step(page, 'warten').click();
    const name = page.getByTestId('step-name');
    await name.fill('abwarten');
    await name.press('Enter');
    await step(page, 'daten_laden').click();
    await expect(page.getByTestId('transition-else').getByTestId('transition-target')).toHaveValue(
      'abwarten',
    );
    await expect(page.getByTestId('flow-lane').nth(1)).toContainText('abwarten');
  });

  test('Bedingung im Assistenten erzeugt den Ausdruck', async ({ page }) => {
    await open(page);
    await step(page, 'speichern').click();
    await page.getByTestId('add-transition').click();
    await page
      .getByTestId('transition-else')
      .last()
      .getByRole('button', { name: 'Bedingung…' })
      .click();
    const card = page.getByTestId('transition-card');
    await card.getByTestId('condition-field').fill('antragFound');
    await card.getByTestId('condition-value').fill('nein');
    await expect(card.getByTestId('condition-expression')).toHaveText(
      "context['antragFound'] == false",
    );
  });

  test('Löschen mit Rückgängig', async ({ page }) => {
    await open(page);
    await page.getByRole('button', { name: 'warten löschen' }).click();
    await expect(step(page, 'warten')).toHaveCount(0);
    await page
      .getByRole('region', { name: 'Meldungen' })
      .getByRole('button', { name: 'Rückgängig' })
      .click();
    await expect(step(page, 'warten')).toHaveCount(1);
  });

  test('Aktiv mit Fehler gesperrt, als Entwurf speicherbar', async ({ page }) => {
    await open(page);
    await page.locator('[data-kind="automatic"]').click();
    await expect(page.getByTestId('step-issues')).toContainText('Keine Aktion gewählt.');
    await expect(page.getByTestId('editor-issues')).toBeVisible();
    await expect(page.getByTestId('editor-save')).toBeDisabled();

    await page.getByTestId('editor-status').getByRole('radio', { name: 'Entwurf' }).click();
    await page.getByTestId('editor-save').click();
    await expect(page.getByTestId('editor-message')).toHaveText(
      'Gespeichert: antrag-pruefung v4 (entwurf).',
    );
    await expect(page.getByTestId('editor-dirty')).toHaveCount(0);
  });

  test('Speichern als Aktiv erzeugt eine neue Version', async ({ page }) => {
    await open(page);
    await page.getByTestId('editor-name').fill('Antrag prüfen (neu)');
    await page.getByTestId('editor-save').click();
    await expect(page.getByTestId('editor-message')).toHaveText(
      'Gespeichert: antrag-pruefung v4 (aktiv).',
    );
    await expect(page.getByTestId('editor-version')).toHaveText('v4');
  });

  test('JSON-Ansicht: ungültiges JSON bleibt mit Meldung', async ({ page }) => {
    await open(page);
    await page.getByRole('radio', { name: 'JSON' }).click();
    const json = page.getByTestId('editor-json');
    await expect(json).toHaveValue(/"startStep": "daten_laden"/);
    await json.fill('{ kaputt');
    await page.getByRole('radio', { name: 'Visuell' }).click();
    await expect(page.getByTestId('editor-message')).toHaveText(
      'Ungültiges JSON – bitte korrigieren.',
    );
    await expect(json).toBeVisible();
  });

  test('Verlassen mit Änderungen fragt nach', async ({ page }) => {
    await open(page);
    await page.getByTestId('editor-name').fill('Geändert');
    await page.getByRole('link', { name: 'Instanzen' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ungespeicherte Änderungen' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button')).toHaveText([
      'Verwerfen',
      'Im Editor bleiben',
      'Speichern und verlassen',
    ]);
    await dialog.getByRole('button', { name: 'Im Editor bleiben' }).click();
    await expect(page).toHaveURL(/antrag-pruefung$/);
    await page.getByRole('link', { name: 'Instanzen' }).click();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Verwerfen' }).click();
    await expect(page).toHaveURL(/\/admin\/instanzen$/);
  });

  test('neuer Workflow: «neu», erster Schritt wird Start, Speichern führt zur eigenen Adresse', async ({
    page,
  }) => {
    await page.goto('/admin/workflows/neu');
    await expect(page.getByTestId('editor-version')).toHaveText('neu');
    await expect(page.getByText('noch keine Schritte')).toBeVisible();
    await page.getByTestId('editor-id').fill('test-ablauf');
    await page.locator('[data-kind="interactive"]').click();
    await expect(step(page, 'schritt_1')).toContainText('Start');
    await page.getByTestId('editor-status').getByRole('radio', { name: 'Entwurf' }).click();
    await page.getByTestId('editor-save').click();
    await expect(page).toHaveURL(/\/admin\/workflows\/test-ablauf$/);
    await expect(page.getByTestId('editor-version')).toHaveText('v1');
  });

  test('Schrittliste per Tastatur', async ({ page }) => {
    await open(page);
    await page.getByTestId('step-list').focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByTestId('step-name')).toHaveValue('pruefen');
  });

  test('dunkel: Theme folgt dem System, Seite scrollt nicht waagrecht', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await open(page);
    await expect(page.locator('wf-admin-shell')).toHaveAttribute('data-theme', 'dunkel');
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
  });
});
