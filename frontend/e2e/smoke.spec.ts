import { expect, test } from '@playwright/test';

test('Demo startet und zeigt die Client-Version', async ({ page }) => {
  await page.goto('/');
  await expect(page.getByText('wrkflw Demo')).toBeVisible();
  await expect(page.getByTestId('client-version')).toHaveText(/^3\./);
});

test('keine waagrechte Scrollleiste', async ({ page }) => {
  for (const path of ['/admin', '/admin/workflows/antrag-pruefung', '/bausteine']) {
    await page.goto(path);
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow, path).toBeLessThanOrEqual(0);
  }
});
