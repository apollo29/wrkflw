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
});
