import { expect, test } from '@playwright/test';

test.describe('Shell der Admin-Oberfläche', () => {
  test('vier Reiter, der aktive ist markiert', async ({ page }) => {
    await page.goto('/admin');
    const nav = page.getByRole('navigation', { name: 'Bereiche' });
    await expect(nav.getByRole('link')).toHaveText([
      'Workflows',
      'Instanzen',
      'Vorlagen',
      'Datenquellen',
    ]);
    await expect(nav.getByRole('link', { name: 'Workflows' })).toHaveAttribute(
      'aria-current',
      'page',
    );

    await nav.getByRole('link', { name: 'Vorlagen' }).click();
    await expect(page).toHaveURL(/\/admin\/vorlagen$/);
    await expect(nav.getByRole('link', { name: 'Vorlagen' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    await expect(page.getByRole('heading', { level: 2, name: 'Vorlagen' })).toBeVisible();
  });

  test('Reiter per Tastatur erreichbar', async ({ page }) => {
    await page.goto('/admin');
    const instances = page.getByRole('link', { name: 'Instanzen' });
    await instances.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/admin\/instanzen$/);
  });

  test('ungespeicherte Änderungen: Rückfrage beim Verlassen', async ({ page }) => {
    await page.goto('/admin/workflows/antrag');
    await page.getByTestId('simulate-change').click();
    await expect(page.getByText('Ungespeicherte Änderungen')).toBeVisible();

    await page.getByRole('link', { name: 'Instanzen' }).click();
    const dialog = page.getByRole('dialog', { name: 'Ungespeicherte Änderungen' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByRole('button')).toHaveText([
      'Verwerfen',
      'Im Editor bleiben',
      'Speichern und verlassen',
    ]);
    await expect(dialog.getByRole('button', { name: 'Verwerfen' })).toBeFocused();

    await dialog.getByRole('button', { name: 'Im Editor bleiben' }).click();
    await expect(dialog).toBeHidden();
    await expect(page).toHaveURL(/\/admin\/workflows\/antrag$/);

    await page.getByRole('link', { name: 'Instanzen' }).click();
    await page.keyboard.press('Escape');
    await expect(page).toHaveURL(/\/admin\/workflows\/antrag$/);

    await page.getByRole('link', { name: 'Instanzen' }).click();
    await page.getByRole('dialog').getByRole('button', { name: 'Speichern und verlassen' }).click();
    await expect(page).toHaveURL(/\/admin\/instanzen$/);
  });
});
