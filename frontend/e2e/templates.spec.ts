import { expect, test } from '@playwright/test';

test.describe('Vorlagen', () => {
  test('Vorlage anlegen: Tabelle, Platzhalter, Bild, Vorschau, speichern, löschen', async ({
    page,
  }) => {
    await page.goto('/admin/vorlagen');
    await page.getByTestId('templates-new').click();
    await page.getByTestId('templates-id').fill('willkommen');
    await page.getByTestId('templates-name').fill('Willkommen');
    await page.getByTestId('templates-subject').fill('Hallo {{name}}');
    await expect(page.getByTestId('templates-dirty')).toBeVisible();

    const content = page.locator('.wf-html__content .ProseMirror');
    await content.click();
    await page.keyboard.type('Guten Tag ');
    await page.getByTestId('html-custom').fill('name');
    await page.getByTestId('html-custom').press('Enter');
    await expect(content.locator('.wf-ph')).toHaveText('{{name}}');

    await page.getByTestId('html-table').click();
    const bar = page.getByTestId('html-tablebar');
    await expect(bar).toBeVisible();
    await bar.getByRole('button', { name: 'Zeile +' }).click();
    await expect(content.locator('table tr')).toHaveCount(4);

    await page.getByTestId('html-image-file').setInputFiles({
      name: 'logo.png',
      mimeType: 'image/png',
      buffer: Buffer.from('x'),
    });
    await expect(content.locator('img[src="/demo-bild.png"]')).toBeVisible();

    await page.getByTestId('html-preview-toggle').click();
    await expect(
      page.frameLocator('[data-testid="html-preview-frame"]').locator('body'),
    ).toContainText('Guten Tag [Beispielwert name]');
    await expect(page.getByTestId('templates-subject-preview')).toContainText(
      'Betreff: Hallo [Beispielwert name]',
    );

    await page.getByTestId('html-source-toggle').click();
    await expect(page.getByTestId('html-source')).toHaveValue(/border:1px solid #d4d4d9/);
    await expect(page.getByTestId('html-source')).toHaveValue(/width="100%"/);
    await page.getByTestId('html-source-toggle').click();

    await page.getByTestId('templates-save').click();
    await expect(page.getByTestId('templates-message')).toContainText('Gespeichert: willkommen.');
    await expect(page).toHaveURL(/id=willkommen/);
    await expect(page.getByTestId('templates-dirty')).toHaveCount(0);
    await expect(page.getByTestId('templates-usage')).toContainText(
      'Wird derzeit von keinem Workflow verwendet.',
    );
    await expect(page.locator('[data-template="willkommen"]')).toHaveAttribute(
      'aria-current',
      'true',
    );

    // Nach dem Neuladen bleibt die Vorlage (Demo speichert im Browser).
    await page.reload();
    await expect(page.getByTestId('templates-name')).toHaveValue('Willkommen');

    await page.getByTestId('templates-delete').click();
    await expect(page.getByRole('dialog')).toContainText('endgültig gelöscht');
    await page.getByRole('dialog').getByRole('button', { name: 'Löschen' }).click();
    await expect(page.getByTestId('templates-message')).toContainText('Gelöscht: willkommen.');
    await expect(page.locator('[data-template="willkommen"]')).toHaveCount(0);
  });

  test('Verwendung ablesen, Vorschläge nutzen und Löschen einer verwendeten Vorlage', async ({
    page,
  }) => {
    await page.goto('/admin/vorlagen?id=antrag-ok');
    const usage = page.getByTestId('templates-usage');
    await expect(usage).toContainText('Verwendet in 3 Schritt(en):');
    await expect(usage).toContainText('Antrag prüfen · v3 · bestaetigung');
    await expect(
      page.getByTestId('html-placeholder').locator('option', { hasText: 'antrag_id' }),
    ).toHaveCount(1);
    await page.getByTestId('html-preview-toggle').click();
    const preview = page.frameLocator('[data-testid="html-preview-frame"]');
    await expect(preview.locator('body')).toContainText('Ihr Antrag A-1024 wurde geprüft');
    // Die Inline-Stile der E-Mail-Tabelle bleiben in der Vorschau erhalten.
    await expect(preview.locator('th').first()).toHaveCSS('border-top-style', 'solid');
    await expect(page.getByTestId('templates-subject-preview')).toContainText(
      'Betreff: Ihr Antrag A-1024 ist geprüft',
    );

    await page.getByTestId('templates-delete').click();
    await expect(page.getByRole('dialog')).toContainText('3 Schritt(en)');
    await page.getByRole('dialog').getByRole('button', { name: 'Löschen' }).click();
    await expect(page.getByTestId('templates-message')).toContainText('wird noch verwendet');
    await expect(page.locator('[data-template="antrag-ok"]')).toBeVisible();

    await usage.getByRole('link').last().click();
    await expect(page).toHaveURL(/\/admin\/workflows\/antrag-pruefung$/);
  });

  test('Seitenvorlagen und Rückfrage bei ungespeicherten Änderungen', async ({ page }) => {
    await page.goto('/admin/vorlagen');
    await page.getByRole('radio', { name: 'Seite' }).click();
    await expect(page).toHaveURL(/type=page/);
    await page.locator('[data-template="antrag-info"]').click();
    await expect(page.getByTestId('templates-subject')).toHaveCount(0);
    await page.getByTestId('templates-name').fill('Geändert');
    await page.getByTestId('templates-new').click();
    await expect(page.getByRole('dialog')).toContainText('noch nicht gespeichert');
    await page.getByRole('dialog').getByRole('button', { name: 'Im Editor bleiben' }).click();
    await expect(page.getByTestId('templates-name')).toHaveValue('Geändert');
  });

  test('dunkel und schmal: ohne waagrechtes Scrollen', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/admin/vorlagen?id=antrag-ok');
    await expect(page.locator('wf-admin-shell')).toHaveAttribute('data-theme', 'dunkel');
    await expect(page.getByTestId('templates-usage')).toContainText('Verwendet in');
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth),
    ).toBeLessThanOrEqual(0);
  });
});
