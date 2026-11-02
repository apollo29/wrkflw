import { expect, test } from '@playwright/test';

test.describe('Übersicht', () => {
  test('eine Zeile je Workflow mit Durchläufen, Suche und Filter', async ({ page }) => {
    await page.goto('/admin');
    const row = page.locator('[data-workflow="antrag-pruefung"]');
    await expect(row).toContainText('Antrag prüfen');
    await expect(row).toContainText('2 laufend · 12 gesamt');
    await expect(row.locator('svg[role="img"]')).toHaveAttribute(
      'aria-label',
      '6 Schritte, 1 Verzweigung(en)',
    );

    await page.getByTestId('overview-search').fill('nichts-da');
    await expect(page.getByText('Keine Workflows gefunden.')).toBeVisible();
    await page.getByTestId('overview-search').fill('');
    await page.getByRole('radio', { name: 'Entwurf' }).click();
    await expect(page.locator('[data-workflow]')).toHaveCount(1);
    await expect(page.locator('[data-workflow="mahnung"]')).toBeVisible();
  });

  test('ältere Versionen: v1 gesperrt, v2 entfernen mit Rückfrage, Ansehen schreibgeschützt', async ({
    page,
  }) => {
    await page.goto('/admin');
    await page.locator('[data-workflow="antrag-pruefung"]').getByTestId('overview-older').click();
    const v1 = page.locator('[data-version="1"]');
    await expect(
      v1.getByRole('button', { name: 'Version v1 entfernen – gesperrt' }),
    ).toBeDisabled();
    await expect(v1).toContainText('Ein abgeschlossener Durchlauf verweist noch darauf.');

    await page
      .locator('[data-version="2"]')
      .getByRole('button', { name: 'Version v2 entfernen' })
      .click();
    const dialog = page.getByRole('dialog', { name: 'Version v2 entfernen?' });
    await dialog.getByRole('button', { name: 'Version v2 entfernen' }).click();
    await expect(page.getByTestId('overview-message')).toHaveText('antrag-pruefung v2 entfernt.');
    await expect(page.locator('[data-version="2"]')).toHaveCount(0);

    await v1.getByRole('link', { name: 'Ansehen' }).click();
    await expect(page).toHaveURL(/workflows\/antrag-pruefung\/v\/1$/);
    await expect(page.getByText('Ältere Version – schreibgeschützt.')).toBeVisible();
    await expect(page.getByTestId('editor-save')).toHaveCount(0);
  });

  test('Zeile und «+ Neuer Workflow» öffnen den Editor, auch per Tastatur', async ({ page }) => {
    await page.goto('/admin');
    await page.getByRole('link', { name: 'Antrag prüfen' }).focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/workflows\/antrag-pruefung$/);
    await page.goto('/admin');
    await page.getByTestId('overview-new').click();
    await expect(page).toHaveURL(/workflows\/neu$/);
  });
});

test.describe('Instanzen', () => {
  test('Filter «Fehlgeschlagen» zeigt den Grund, Verlauf nur mit Feldnamen', async ({ page }) => {
    await page.goto('/admin/instanzen');
    await page
      .getByRole('group', { name: 'Filter nach Status' })
      .getByRole('button', { name: 'Fehlgeschlagen' })
      .click();
    await expect(page).toHaveURL(/status=failed/);
    await expect(page.locator('tr[data-instance]')).toHaveCount(1);
    await expect(page.getByTestId('instance-error')).toHaveText(
      'Mailversand: Zeitüberschreitung nach 3 Versuchen.',
    );
    await page.getByTestId('instance-history').click();
    await expect(page.locator('.wf-in-hist')).toContainText(
      'Ereignis submit in pruefen · Felder: bemerkung',
    );
    await expect(page.locator('.wf-in-hist')).toContainText('daten_laden ausgeführt → pruefen');
  });

  test('Durchlauf abbrechen mit Rückfrage', async ({ page }) => {
    await page.goto('/admin/instanzen?status=waiting_event');
    await page.getByTestId('instance-history').click();
    await page.getByTestId('instance-cancel').click();
    await page
      .getByRole('dialog', { name: 'Durchlauf abbrechen?' })
      .getByRole('button', { name: 'Abbrechen' })
      .click();
    await expect(page.getByRole('region', { name: 'Meldungen' })).toContainText(
      'Durchlauf abgebrochen.',
    );
    await expect(page.getByText('Keine Durchläufe mit diesem Status.')).toBeVisible();
  });
});
