import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-End-Abnahme (PLAN.md, Phase 12: Abschnitte 9.15 und 9.16).
 *
 * Lokal startet Playwright die Demo selbst. Ein bereits installiertes Chromium lässt
 * sich über PW_CHROMIUM_PATH angeben; sonst gilt `npx playwright install chromium`.
 */
const port = Number(process.env['E2E_PORT'] ?? 4300);
const executablePath = process.env['PW_CHROMIUM_PATH'] || undefined;

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 1 : 0,
  reporter: process.env['CI'] ? [['list'], ['html', { open: 'never' }]] : 'list',
  use: {
    baseURL: `http://localhost:${port}`,
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'], launchOptions: { executablePath } },
    },
    {
      name: 'schmal',
      use: { ...devices['Desktop Chrome'], viewport: { width: 375, height: 812 }, launchOptions: { executablePath } },
    },
  ],
  webServer: {
    command: `npx ng serve demo --port ${port}`,
    url: `http://localhost:${port}`,
    reuseExistingServer: !process.env['CI'],
    timeout: 180_000,
  },
});
