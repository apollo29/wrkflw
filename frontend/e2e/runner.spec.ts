import { expect, Page, test } from '@playwright/test';

async function startAntrag(page: Page): Promise<void> {
  await page.goto('/durchspielen');
  await page.getByTestId('playground-workflow').selectOption('antrag-pruefung');
  await expect(page.locator('[data-input="antrag_id"]')).toHaveValue('A-1024');
  await page.getByTestId('playground-start').click();
  await expect(page.getByRole('heading', { level: 2, name: 'Antrag prüfen' })).toBeVisible();
}

test.describe('Runner', () => {
  test('Ablauf durchspielen: Felder, Datei ablehnen und annehmen, zurück, Abschluss', async ({
    page,
  }) => {
    await startAntrag(page);
    await expect(page.getByTestId('runner-page')).toContainText('Antrag A-1024');
    await expect(page.getByTestId('runner-display')).toHaveText('1250');
    const freigeben = page.getByRole('button', { name: 'Freigeben' });

    await page.locator('[data-field="bemerkung"]').fill('Alles in Ordnung');
    await page.getByLabel('Unterlagen vollständig').check();
    const file = page.locator('[data-field="beleg"]');
    await file.setInputFiles({ name: 'foto.png', mimeType: 'image/png', buffer: Buffer.from('x') });
    await expect(page.getByText('«foto.png» wurde abgelehnt')).toBeVisible();
    await file.setInputFiles({
      name: 'beleg.pdf',
      mimeType: 'application/pdf',
      buffer: Buffer.from('%PDF'),
    });
    await expect(page.getByText('Hochgeladen: beleg.pdf')).toBeVisible();

    await freigeben.click();
    await expect(page.getByRole('heading', { level: 2, name: 'Zusammenfassung' })).toBeVisible();
    await expect(page.getByTestId('runner-display').first()).toHaveText('Alles in Ordnung');
    await page.getByTestId('runner-back').click();
    await expect(page.getByRole('heading', { level: 2, name: 'Antrag prüfen' })).toBeVisible();
    await expect(page.locator('[data-field="bemerkung"]')).toHaveValue('Alles in Ordnung');
    await expect(page.getByText('Bereits hochgeladen.')).toBeVisible();

    await page.getByRole('button', { name: 'Freigeben' }).click();
    await page.getByRole('button', { name: 'Bestätigen' }).click();
    await expect(page.getByTestId('runner-state')).toContainText('Der Ablauf ist abgeschlossen.');
  });

  test('zweiter Ausgang «Ablehnen» und Tastatur', async ({ page }) => {
    await startAntrag(page);
    await expect(page.getByRole('heading', { level: 2, name: 'Antrag prüfen' })).toBeFocused();
    await page.locator('[data-field="bemerkung"]').focus();
    await page.keyboard.type('fehlt');
    await page.getByRole('button', { name: 'Ablehnen' }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('runner-state')).toContainText('Der Ablauf ist abgeschlossen.');
  });

  test('wartenden Durchlauf aus «Instanzen» bearbeiten', async ({ page }) => {
    await page.goto('/admin/instanzen?status=waiting_event');
    await page.getByTestId('instance-edit').click();
    await expect(page).toHaveURL(/\/admin\/instanzen\/i-1024/);
    await expect(page.getByRole('heading', { level: 2, name: 'Antrag prüfen' })).toBeVisible();
    await expect(page.getByText('Subjekt: A-1024')).toBeVisible();
    await page.getByRole('button', { name: 'Ablehnen' }).click();
    await expect(page.getByTestId('runner-state')).toContainText('Der Ablauf ist abgeschlossen.');
    await page.getByRole('link', { name: '← Alle Durchläufe' }).click();
    await expect(page.getByText('Keine Durchläufe mit diesem Status.')).toBeVisible();
  });

  test('dunkel: Runner ohne waagrechtes Scrollen', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await startAntrag(page);
    await expect(page.locator('demo-playground')).toHaveAttribute('data-theme', 'dunkel');
    expect(
      await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth),
    ).toBeLessThanOrEqual(0);
  });
});
