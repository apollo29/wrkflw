import { expect, test } from '@playwright/test';

test('Demo startet und zeigt die Client-Version', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByRole('heading', { name: 'wrkflw Demo' })).toBeVisible();
  await expect(page.getByTestId('client-version')).toHaveText(/^3\./);
});

test('keine waagrechte Scrollleiste', async ({ page }) => {
  await page.goto('/');
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});
