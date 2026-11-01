import { expect, test } from '@playwright/test';

test.describe('Bausteine', () => {
  test('hell und dunkel umschalten', async ({ page }) => {
    await page.goto('/bausteine');
    const root = page.locator('demo-gallery');
    const themes = page.getByRole('radiogroup', { name: 'Darstellung' });

    await themes.getByRole('radio', { name: 'Dunkel' }).click();
    await expect(root).toHaveAttribute('data-theme', 'dunkel');
    const darkBg = await page
      .locator('.wf-zone')
      .first()
      .evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(darkBg).toBe('rgb(21, 21, 24)');

    await themes.getByRole('radio', { name: 'Hell' }).click();
    await expect(root).toHaveAttribute('data-theme', 'hell');
    const lightBg = await page
      .locator('.wf-zone')
      .first()
      .evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(lightBg).toBe('rgb(255, 255, 255)');
  });

  test('Segment per Pfeiltaste', async ({ page }) => {
    await page.goto('/bausteine');
    const group = page.getByRole('radiogroup', { name: 'Status beim nächsten Speichern' });
    await group.getByRole('radio', { name: 'Aktiv', exact: true }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(group.getByRole('radio', { name: 'Entwurf' })).toHaveAttribute(
      'aria-checked',
      'true',
    );
    await expect(group.getByRole('radio', { name: 'Entwurf' })).toBeFocused();
  });

  test('Feld mit Fehler ist verknüpft', async ({ page }) => {
    await page.goto('/bausteine');
    const input = page.getByLabel('Anzeigename');
    await expect(input).toHaveAttribute('aria-invalid', 'true');
    await expect(input).toHaveAccessibleDescription(
      /So erscheint der Workflow.*Bitte einen Namen angeben/,
    );
    await input.fill('Antrag');
    await expect(input).not.toHaveAttribute('aria-invalid', 'true');
  });

  test('Meldung mit Rückgängig', async ({ page }) => {
    await page.goto('/bausteine');
    await page.getByTestId('show-toast').click();
    const region = page.getByRole('region', { name: 'Meldungen' });
    await expect(region).toContainText('Schritt «Prüfen» gelöscht.');
    await region.getByRole('button', { name: 'Rückgängig' }).click();
    await expect(region).toContainText('Wiederhergestellt.');
  });
});

test.describe('schmal', () => {
  test.skip(({ viewport }) => (viewport?.width ?? 0) > 400, 'nur auf 375 px');

  test('Segment füllt die Breite, Knöpfe sind Touch-Ziele', async ({ page }) => {
    await page.goto('/bausteine');
    const group = page.getByRole('radiogroup', { name: 'Status beim nächsten Speichern' });
    const groupBox = await group.boundingBox();
    const zoneBox = await page.locator('.wf-zone').nth(2).boundingBox();
    expect(groupBox && zoneBox && groupBox.width).toBeGreaterThan((zoneBox?.width ?? 0) - 40);

    const button = await page.getByRole('button', { name: 'Speichern', exact: true }).boundingBox();
    expect(button?.height).toBeGreaterThanOrEqual(44);
  });
});
