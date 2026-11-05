import { expect, test } from '@playwright/test';

test.describe('Datenquellen (9.16)', () => {
  test('Verbindung anlegen, testen, Zugangsdaten ersetzen – das Geheimnis taucht nie auf', async ({
    page,
  }) => {
    await page.goto('/admin/datenquellen');
    await page.getByTestId('ds-new').click();
    await page.getByTestId('conn-id').fill('kurs-api');
    await page.getByTestId('conn-name').fill('Kurs-API');
    await page.getByTestId('conn-url').fill('http://127.0.0.1');
    await page.getByTestId('conn-auth').selectOption('bearer');
    await page.getByTestId('conn-secret').fill('erstes-geheimnis');
    await page.getByTestId('ds-save').click();
    await expect(page.getByText('Die Adresse muss mit https:// beginnen.')).toBeVisible();

    await page.getByTestId('conn-url').fill('https://127.0.0.1');
    await page.getByTestId('ds-save').click();
    // Die Engine (hier die Demo) begründet die Ablehnung am Feld.
    await expect(
      page.getByText('Die Adresse zeigt auf ein internes Netz und ist gesperrt.'),
    ).toBeVisible();

    await page.getByTestId('conn-url').fill('https://kurse.example.org');
    await page.getByTestId('ds-save').click();
    await expect(page.getByTestId('ds-message')).toContainText('Gespeichert: kurs-api.');
    await expect(page).toHaveURL(/id=kurs-api/);
    await expect(page.getByTestId('conn-stored')).toHaveText('Hinterlegt');

    await page.getByTestId('conn-test').click();
    await expect(page.getByTestId('conn-test-result')).toHaveText(/200 · \d+ ms/);

    await page.getByTestId('conn-replace').click();
    await page.getByTestId('conn-secret').fill('zweites-geheimnis');
    await page.getByTestId('conn-replace-apply').click();
    await expect(page.getByTestId('ds-message')).toContainText('Zugangsdaten ersetzt.');
    await expect(page.getByTestId('conn-secret')).toHaveCount(0);

    await page.getByTestId('ds-audit').locator('summary').click();
    await expect(page.getByTestId('ds-audit')).toContainText('Zugangsdaten ersetzt von Demo');
    await expect(page.getByTestId('ds-audit')).toContainText('Angelegt von Demo');

    for (const secret of ['erstes-geheimnis', 'zweites-geheimnis']) {
      expect(await page.content()).not.toContain(secret);
      expect(await page.evaluate(() => JSON.stringify(localStorage))).not.toContain(secret);
    }

    await page.getByTestId('ds-delete').click();
    await page.getByRole('dialog').getByRole('button', { name: 'Löschen' }).click();
    await expect(page.getByTestId('ds-message')).toContainText('Gelöscht: kurs-api.');
  });

  test('verwendete Verbindung: Löschen gesperrt, Link zum Datenmodell', async ({ page }) => {
    await page.goto('/admin/datenquellen?bereich=verbindungen&id=antrag-api');
    await expect(page.getByTestId('ds-usage')).toContainText('Verwendet von 1 Datenmodell(en):');
    await expect(page.getByTestId('ds-delete')).toBeDisabled();
    await expect(page.getByText('Löschen gesperrt: wird noch verwendet.')).toBeVisible();
    await page.getByTestId('ds-usage').getByRole('link', { name: 'antrag' }).click();
    await expect(page).toHaveURL(/bereich=modelle&id=antrag/);
    await expect(page.getByTestId('model-label')).toHaveValue('Anträge');
  });

  test('Datenmodell: Beispiel abrufen, Feld übernehmen, gesperrtes «×»', async ({ page }) => {
    await page.goto('/admin/datenquellen?bereich=modelle&id=antrag');
    const status = page.locator('[data-field="status"]');
    await expect(status.getByRole('button')).toBeDisabled();
    await expect(status.getByRole('button')).toHaveAttribute(
      'title',
      /antrag-pruefung v3 · daten_laden/,
    );
    await expect(page.getByTestId('ds-delete')).toBeDisabled();

    await page.getByTestId('model-sample-id').fill('A-1024');
    await page.getByTestId('model-sample').click();
    await expect(page.getByTestId('model-sample-result')).toHaveText(/200 · \d+ ms/);
    const tree = page.getByTestId('model-sample-tree');
    await expect(tree.locator('[data-path="data.status"]')).toBeDisabled();
    await tree.locator('[data-path="data.archiviert"]').click();
    const archiviert = page.locator('[data-field="archiviert"]');
    await expect(archiviert).toBeVisible();
    await expect(archiviert.getByRole('combobox', { name: 'Typ' })).toHaveValue('bool');
    await expect(tree.locator('[data-path="data.archiviert"]')).toBeDisabled();
    await expect(page.getByTestId('ds-dirty')).toBeVisible();

    await page.getByTestId('ds-save').click();
    await expect(page.getByTestId('ds-message')).toContainText('Gespeichert: antrag.');
    await expect(page.getByTestId('ds-dirty')).toHaveCount(0);
  });

  test('Trigger anlegen mit Bedingung und Startkontext, «Jetzt prüfen»', async ({ page }) => {
    await page.goto('/admin/datenquellen?bereich=trigger');
    await page.getByTestId('ds-new').click();
    await page.getByTestId('trigger-id').fill('grosse-antraege');
    await page.getByTestId('trigger-name').fill('Grosse Anträge');
    await page.getByTestId('trigger-model').selectOption('antrag');
    await page.getByTestId('trigger-workflow').selectOption('antrag-pruefung');
    await expect(page.locator('[data-input="antrag_id"]')).toContainText('(Pflicht)');
    await page.getByTestId('trigger-condition-add').click();
    const row = page.getByTestId('trigger-conditions').locator('.wf-ds__brow').first();
    await row.getByRole('combobox', { name: 'Feld' }).selectOption('betrag');
    await row.getByRole('combobox', { name: 'Vergleich' }).selectOption('>');
    await row.getByRole('spinbutton', { name: 'Wert' }).fill('1000');
    await page.getByTestId('trigger-every').fill('2');
    await page.getByTestId('ds-save').click();
    await expect(page.getByText('Die Pflichtangabe braucht ein Feld.')).toBeVisible();
    await expect(page.getByText('Bitte die markierten Angaben prüfen.')).toBeVisible();

    await page.getByRole('combobox', { name: 'Feld für antrag_id' }).selectOption('id');
    await page.getByTestId('trigger-unit').selectOption('h');
    await page.getByTestId('ds-save').click();
    await expect(page.getByTestId('ds-message')).toContainText('Gespeichert: grosse-antraege.');
    await expect(page.getByTestId('trigger-unit')).toHaveValue('h');
    await expect(page.getByTestId('trigger-active')).not.toBeChecked();

    await page.getByTestId('trigger-check').click();
    await expect(page.getByTestId('trigger-check-result')).toHaveText(
      '3 Datensätze treffen aktuell zu. Gestartet wird nichts.',
    );
    await expect(page.locator('[data-item="grosse-antraege"]')).toContainText(
      'antrag → antrag-pruefung',
    );
  });

  test('dunkel und schmal: ohne waagrechtes Scrollen', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    for (const url of [
      '/admin/datenquellen?bereich=verbindungen&id=antrag-api',
      '/admin/datenquellen?bereich=modelle&id=antrag',
      '/admin/datenquellen?bereich=trigger&id=offene-antraege',
    ]) {
      await page.goto(url);
      await expect(page.locator('wf-admin-shell')).toHaveAttribute('data-theme', 'dunkel');
      await expect(page.getByTestId('ds-save')).toBeVisible();
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth),
      ).toBeLessThanOrEqual(0);
    }
  });
});
